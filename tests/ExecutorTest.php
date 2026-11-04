<?php declare(strict_types=1);

namespace danog\MadelineCrypto\Test;

use danog\MadelineProto\Native\Crypto;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * The executor (worker thread + php-tokio bridge to Revolt) and the synchronous decoder, against the PHP
 * references: results, error codes, concurrency and shutdown.
 */
final class ExecutorTest extends TestCase
{
    private const UNAVAILABLE = 1;
    private const INVALID = 2;

    /** @var resource|null */
    private $stream = null;
    private ?string $watcher = null;

    protected function setUp(): void
    {
        $this->stream = Crypto::init(128, 128 << 20);
        stream_set_blocking($this->stream, false);
        $this->watcher = EventLoop::onReadable($this->stream, static fn () => Crypto::wakeup());
        self::assertTrue(Crypto::available());
    }

    protected function tearDown(): void
    {
        Crypto::shutdown();
        if ($this->watcher !== null) {
            EventLoop::cancel($this->watcher);
        }
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
        self::assertFalse(Crypto::available());
    }

    /** Runs the closures concurrently, each in its own fiber, and returns their results. */
    private static function concurrently(array $operations): array
    {
        $results = [];
        $pending = \count($operations);
        $done = EventLoop::getSuspension();
        foreach ($operations as $i => $operation) {
            EventLoop::queue(static function () use ($i, $operation, &$results, &$pending, $done): void {
                try {
                    $results[$i] = $operation();
                } catch (\Throwable $e) {
                    $results[$i] = $e;
                }
                if (!--$pending) {
                    $done->resume();
                }
            });
        }
        if ($pending) {
            $done->suspend();
        }
        ksort($results);
        return $results;
    }

    public function testIgeMatchesTheReferenceUnderConcurrency(): void
    {
        $cases = [];
        foreach ([16, 32, 1024, 16384, 65536, 512 * 1024] as $size) {
            for ($i = 0; $i < 8; $i++) {
                $cases[] = [random_bytes(32), random_bytes(32), random_bytes($size), (bool) ($i % 2)];
            }
        }
        $results = self::concurrently(array_map(
            static fn (array $c) => static fn () => Crypto::ige($c[2], $c[0], $c[1], $c[3]),
            $cases,
        ));
        foreach ($cases as $i => [$key, $iv, $data, $encrypt]) {
            self::assertIsArray($results[$i], (string) ($results[$i] instanceof \Throwable ? $results[$i] : ''));
            self::assertSame(Reference::ige($key, $iv, $data, $encrypt), $results[$i][0], "case $i");
        }
    }

    public function testDecodeMatchesTheReferenceAndTheSyncDecoder(): void
    {
        $auth = random_bytes(256);
        $cases = [];
        foreach ([0, 4, 100, 1000, 16384, 100000, 1 << 20] as $size) {
            $body = $size ? random_bytes($size) : '';
            $session = random_bytes(8);
            $messageId = random_bytes(8);
            $seqno = random_int(0, 1 << 30);
            [$cipher, $messageKey] = Reference::message($auth, $body, random_bytes(8), $session, $messageId, $seqno);
            // The header: session ID, message ID, seqno (the salt isn't returned).
            $cases[] = [$cipher, $messageKey, $body, $session.$messageId.pack('V', $seqno)];
        }
        $results = self::concurrently(array_map(
            static fn (array $c) => static fn () => Crypto::decode($c[0], $auth, $c[1]),
            $cases,
        ));
        foreach ($cases as $i => [$cipher, $messageKey, $body, $header]) {
            self::assertIsArray($results[$i], (string) ($results[$i] instanceof \Throwable ? $results[$i] : ''));
            self::assertSame($body, $results[$i][0], "body, case $i");
            self::assertSame($header, substr($results[$i][1], 0, 20), "header, case $i");
            self::assertSame($results[$i], Crypto::decodeSync($cipher, $auth, $messageKey), "sync, case $i");
        }
    }

    public function testForgedMessagesFailWithTheSameCodeBothWays(): void
    {
        $auth = random_bytes(256);
        [$cipher, $messageKey] = Reference::message($auth, random_bytes(100), random_bytes(8), random_bytes(8), random_bytes(8), 1);
        $forgeries = [
            'message key' => [$cipher, $auth, ($messageKey[0] ^ "\1").substr($messageKey, 1)],
            'ciphertext' => [substr($cipher, 0, -1).(substr($cipher, -1) ^ "\1"), $auth, $messageKey],
            'auth key' => [$cipher, random_bytes(256), $messageKey],
        ];
        foreach ($forgeries as $what => [$c, $a, $k]) {
            [$async] = self::concurrently([static fn () => Crypto::decode($c, $a, $k)]);
            self::assertInstanceOf(\Exception::class, $async, $what);
            self::assertSame(self::INVALID, $async->getCode(), $what);
            try {
                Crypto::decodeSync($c, $a, $k);
                self::fail("Sync decode accepted a forged $what");
            } catch (\Exception $e) {
                self::assertSame(self::INVALID, $e->getCode(), $what);
            }
        }
    }

    public function testOperationsAfterStopAreUnavailable(): void
    {
        Crypto::stop();
        self::assertFalse(Crypto::available());
        [$result] = self::concurrently([static fn () => Crypto::ige(random_bytes(32), random_bytes(32), random_bytes(32), true)]);
        self::assertInstanceOf(\Exception::class, $result);
        self::assertSame(self::UNAVAILABLE, $result->getCode());
        // The synchronous paths don't need the executor.
        $auth = random_bytes(256);
        $body = random_bytes(52); // MTProto bodies are 4-byte aligned
        [$cipher, $messageKey] = Reference::message($auth, $body, random_bytes(8), random_bytes(8), random_bytes(8), 3);
        self::assertSame($body, Crypto::decodeSync($cipher, $auth, $messageKey)[0]);
    }
}
