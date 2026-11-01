<?php

namespace App\SignIn\Passkeys;

/**
 * A passkey's COSE public key (RFC 9053) as PEM for OpenSSL: ES256 (P-256, what nearly every passkey uses) and RS256
 * (Windows Hello). Ed25519 is not offered to browsers, so it never arrives.
 */
final class CoseKey
{
    public const ES256 = -7;

    public const RS256 = -257;

    /** @param  array<int, mixed>  $key */
    public static function toPem(array $key): string
    {
        return match ($key[3] ?? null) {
            self::ES256 => self::ec2($key),
            self::RS256 => self::rsa($key),
            default => throw new PasskeyException('Unsupported passkey algorithm'),
        };
    }

    /** @param  array<int, mixed>  $key */
    private static function ec2(array $key): string
    {
        // kty 2 (EC2), crv 1 (P-256), x and y 32 bytes each
        if (($key[1] ?? null) !== 2 || ($key[-1] ?? null) !== 1 || strlen($key[-2] ?? '') !== 32 || strlen($key[-3] ?? '') !== 32) {
            throw new PasskeyException('Not a P-256 key');
        }

        // SubjectPublicKeyInfo for id-ecPublicKey / prime256v1, then the uncompressed point
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$key[-2].$key[-3];

        return self::pem($der);
    }

    /** @param  array<int, mixed>  $key */
    private static function rsa(array $key): string
    {
        if (($key[1] ?? null) !== 3 || ! is_string($key[-1] ?? null) || ! is_string($key[-2] ?? null) || strlen($key[-1]) < 256) {
            throw new PasskeyException('Not an RSA key of 2048 bits or more');
        }

        $public = self::sequence(self::integer($key[-1]).self::integer($key[-2]));
        $algorithm = self::sequence(hex2bin('06092a864886f70d0101010500')); // rsaEncryption, NULL

        return self::pem(self::sequence($algorithm."\x03".self::length(strlen($public) + 1)."\x00".$public));
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function pem(string $der): string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        if (openssl_pkey_get_public($pem) === false) {
            throw new PasskeyException('OpenSSL refused the key');
        }

        return $pem;
    }
}
