<?php declare(strict_types=1);

namespace danog\MadelineCrypto\Test;

use danog\MadelineProto\Native\AesCbc;
use danog\MadelineProto\Native\AesCtr;
use danog\MadelineProto\Native\AesCtrKey;
use danog\MadelineProto\Native\AesGcm;
use danog\MadelineProto\Native\AesIge;
use danog\MadelineProto\Native\Crypto;
use danog\MadelineProto\Native\Hash;
use danog\MadelineProto\Native\Hmac;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * The primitives against the PHP builtins they replace (and a PHP IGE), on random inputs.
 */
final class PrimitivesTest extends TestCase
{
    private const ALGOS = ['md5', 'sha1', 'sha256', 'sha384', 'sha512'];
    /** Packet sizes plus the edges of blocks and of OpenSSL's internal chunks. */
    private const SIZES = [0, 1, 15, 16, 17, 31, 63, 64, 65, 127, 128, 129, 160, 1200, 2047, 2048, 2049, 4095, 65537];

    public function testTheExtensionIsLoaded(): void
    {
        self::assertTrue(\extension_loaded('madeline_crypto'), 'Run the tests with -d extension=madeline_crypto');
        self::assertSame('0.5.0', Crypto::version());
        self::assertSame((string) phpversion('madeline_crypto'), (new \ReflectionExtension('madeline_crypto'))->getVersion());
    }

    /** Large inputs: 512 KiB file parts, through the EVP path of GCM, and through CTR and CBC. */
    public function testLargeInputsMatchOpenssl(): void
    {
        $data = self::rnd(512 * 1024);
        foreach ([16, 24, 32] as $keyLength) {
            $key = self::rnd($keyLength);
            $bits = $keyLength * 8;
            $nonce = self::rnd(12);
            $expected = openssl_encrypt($data, "aes-$bits-gcm", $key, OPENSSL_RAW_DATA, $nonce, $tag, 'aad', 16);
            $gcm = new AesGcm($key);
            self::assertSame($expected.$tag, $gcm->encrypt($nonce, 'aad', $data));
            self::assertSame($data, $gcm->decrypt($nonce, 'aad', $expected, $tag));
            $iv = self::rnd(16);
            self::assertSame(openssl_encrypt($data, "aes-$bits-ctr", $key, OPENSSL_RAW_DATA, $iv), AesCtr::once($key, $iv, $data));
            $cbc = (string) openssl_encrypt($data, "aes-$bits-cbc", $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            self::assertSame($cbc, AesCbc::encrypt($key, $iv, $data));
            self::assertSame($data, AesCbc::decrypt($key, $iv, $cbc));
        }
    }

    public function testAesGcmMatchesOpenssl(): void
    {
        foreach ([16, 24, 32] as $keyLength) {
            $key = self::rnd($keyLength);
            $gcm = new AesGcm($key);
            $cipher = 'aes-'.($keyLength * 8).'-gcm';
            foreach (self::SIZES as $size) {
                $plain = self::rnd($size);
                $nonce = self::rnd(12);
                $aad = self::rnd(random_int(0, 40));
                $expected = openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
                $sealed = $gcm->encrypt($nonce, $aad, $plain);
                self::assertSame($expected.$tag, $sealed, "$cipher, $size bytes");
                self::assertSame($plain, $gcm->decrypt($nonce, $aad, $expected, $tag));
                $forged = $tag;
                $byte = random_int(0, 15);
                $forged[$byte] = $forged[$byte] ^ "\x01";
                self::assertFalse($gcm->decrypt($nonce, $aad, $expected, $forged));
                self::assertFalse($gcm->decrypt($nonce, $aad."x", $expected, $tag));
            }
        }
        foreach ([[self::rnd(15)], [self::rnd(33)]] as [$key]) {
            $this->assertValueError(static fn () => new AesGcm($key));
        }
        $gcm = new AesGcm(self::rnd(16));
        $this->assertValueError(static fn () => $gcm->encrypt(self::rnd(16), '', 'x'));
        $this->assertValueError(static fn () => $gcm->decrypt(self::rnd(12), '', 'x', self::rnd(8)));
    }

    public function testAesCtrMatchesOpenssl(): void
    {
        foreach ([16, 24, 32] as $keyLength) {
            $key = self::rnd($keyLength);
            $cipher = 'aes-'.($keyLength * 8).'-ctr';
            // An IV close to the 128-bit wraparound, and random ones.
            foreach ([str_repeat("\xff", 15)."\xf0", self::rnd(16)] as $iv) {
                $plain = self::rnd(5000);
                $expected = openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA, $iv);
                self::assertSame($expected, AesCtr::once($key, $iv, $plain));
                // Byte-granular continuation.
                $ctr = new AesCtr($key, $iv);
                $out = '';
                for ($offset = 0; $offset < \strlen($plain); $offset += $length) {
                    $length = random_int(0, 100);
                    $out .= $ctr->process(substr($plain, $offset, $length));
                }
                self::assertSame($expected, $out);
                $keyed = new AesCtrKey($key);
                foreach (self::SIZES as $size) {
                    $iv = self::rnd(16);
                    $data = self::rnd($size);
                    self::assertSame(openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv), $keyed->apply($iv, $data));
                }
            }
        }
        $this->assertValueError(static fn () => new AesCtr(self::rnd(16), self::rnd(8)));
        $this->assertValueError(static fn () => (new AesCtrKey(self::rnd(16)))->apply(self::rnd(12), 'x'));
    }

    public function testAesCbcAndIgeMatchTheirFallbacks(): void
    {
        foreach ([16, 24, 32] as $keyLength) {
            $key = self::rnd($keyLength);
            $iv = self::rnd(16);
            $cipher = 'aes-'.($keyLength * 8).'-cbc';
            foreach ([0, 16, 32, 1024] as $size) {
                $plain = self::rnd($size);
                $expected = (string) openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                self::assertSame($expected, AesCbc::encrypt($key, $iv, $plain));
                self::assertSame($plain, AesCbc::decrypt($key, $iv, $expected));
            }
        }
        $this->assertValueError(static fn () => AesCbc::encrypt(self::rnd(32), self::rnd(16), 'x'));

        $key = self::rnd(32);
        $iv = self::rnd(32);
        $plain = self::rnd(4096);
        $expected = Reference::ige($key, $iv, $plain, true);
        self::assertSame($expected, AesIge::encrypt($key, $iv, $plain));
        self::assertSame($plain, AesIge::decrypt($key, $iv, $expected));
        $this->assertValueError(static fn () => AesIge::encrypt($key, $iv, 'x'));
        $this->assertValueError(static fn () => AesIge::encrypt($key, self::rnd(16), ''));
    }

    public function testHashesAndHmacsMatchPhp(): void
    {
        foreach (self::ALGOS as $algo) {
            foreach (self::SIZES as $size) {
                $data = self::rnd($size);
                self::assertSame(hash($algo, $data, true), Hash::digest($algo, $data), "$algo, $size bytes");
                $incremental = new Hash($algo);
                for ($offset = 0; $offset < $size; $offset += $length) {
                    $length = random_int(1, 200);
                    $incremental->update(substr($data, $offset, $length));
                }
                self::assertSame(hash($algo, $data, true), $incremental->final());
                foreach ([0, 20, 64, 128, 129, 300] as $keyLength) {
                    $key = self::rnd($keyLength);
                    $expected = hash_hmac($algo, $data, $key, true);
                    self::assertSame($expected, Hmac::compute($algo, $key, $data));
                    $hmac = new Hmac($algo, $key);
                    self::assertSame($expected, $hmac->mac($data));
                    self::assertSame($expected, $hmac->mac($data));
                }
            }
        }
        $finished = new Hash('sha1');
        $finished->final();
        try {
            $finished->update('x');
            self::fail('A finalized hash was updated');
        } catch (\Error $e) {
            self::assertNotInstanceOf(ValueError::class, $e);
        }
        $this->assertValueError(static fn () => Hash::digest('sha3-256', ''));
        $this->assertValueError(static fn () => new Hmac('crc32b', ''));
    }

    /**
     * Serializing would silently drop the key: the classes are not serializable (nor clonable), and neither are the
     * MadelineProto wrappers that hold them.
     */
    public function testKeyObjectsAreNeitherClonableNorSerializable(): void
    {
        $objects = [
            new AesGcm(self::rnd(16)), new AesCtr(self::rnd(16), self::rnd(16)), new AesCtrKey(self::rnd(16)),
            new Hmac('sha1', 'k'), new Hash('sha1'),
        ];
        foreach ($objects as $object) {
            self::assertSame('Error', self::failure(static fn () => clone $object), 'Cloned '.$object::class);
            self::assertNotSame('', self::failure(static fn () => serialize($object)), 'Serialized '.$object::class);
        }
        foreach ([AesGcm::class, AesCtr::class, AesCtrKey::class, AesCbc::class, AesIge::class, Hmac::class, Hash::class] as $class) {
            $payload = 'O:'.\strlen($class).':"'.$class.'":0:{}';
            self::assertSame('Exception', self::failure(static fn () => unserialize($payload)), "Unserialized $class");
        }
    }

    /** The class of what the operation threw, or '' if it didn't. */
    private static function failure(\Closure $operation): string
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            return $e::class;
        }
        return '';
    }

    private function assertValueError(\Closure $operation): void
    {
        try {
            $operation();
        } catch (ValueError) {
            $this->addToAssertionCount(1);
            return;
        }
        self::fail('No ValueError');
    }

    private static function rnd(int $length): string
    {
        return $length ? random_bytes($length) : '';
    }

}
