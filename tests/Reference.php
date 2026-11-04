<?php declare(strict_types=1);

namespace danog\MadelineCrypto\Test;

/** Plain PHP references for what PHP has no builtin for: AES-256-IGE and MTProto 2.0 transport messages. */
final class Reference
{
    public static function ige(string $key, string $iv, string $data, bool $encrypt): string
    {
        $ivA = substr($iv, 0, 16);
        $ivB = substr($iv, 16, 16);
        $out = '';
        $flags = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
        for ($i = 0; $i < \strlen($data); $i += 16) {
            $block = substr($data, $i, 16);
            if ($encrypt) {
                $c = openssl_encrypt($block ^ $ivA, 'aes-256-ecb', $key, $flags) ^ $ivB;
                [$ivA, $ivB] = [$c, $block];
            } else {
                $c = openssl_decrypt($block ^ $ivB, 'aes-256-ecb', $key, $flags) ^ $ivA;
                [$ivA, $ivB] = [$block, $c];
            }
            $out .= $c;
        }
        return $out;
    }

    /** The AES key and IV of an MTProto 2.0 message (x = 8: from the server, the side decode() reads). */
    public static function kdf(string $messageKey, string $auth): array
    {
        $a = hash('sha256', $messageKey.substr($auth, 8, 36), true);
        $b = hash('sha256', substr($auth, 48, 36).$messageKey, true);
        return [
            substr($a, 0, 8).substr($b, 8, 16).substr($a, 24, 8),
            substr($b, 0, 8).substr($a, 8, 16).substr($b, 24, 8),
        ];
    }

    /**
     * An encrypted server message: salt, session, message ID, seqno, length, body, 12-1024 bytes of padding.
     *
     * @return array{string, string, string} The ciphertext, its message key, and the plaintext.
     */
    public static function message(string $auth, string $body, string $salt, string $session, string $messageId, int $seqno): array
    {
        $plain = $salt.$session.$messageId.pack('V', $seqno).pack('V', \strlen($body)).$body;
        $padding = 12 + (16 - (\strlen($plain) + 12) % 16) % 16 + 16 * random_int(0, 3);
        $plain .= random_bytes($padding);
        $messageKey = substr(hash('sha256', substr($auth, 96, 32).$plain, true), 8, 16);
        [$key, $iv] = self::kdf($messageKey, $auth);
        return [self::ige($key, $iv, $plain, true), $messageKey, $plain];
    }
}
