<?php

namespace Plugins\WhmcsImport;

/**
 * WHMCS's own encrypt() / decrypt() (passwords in tblhosting and tblservers), keyed by
 * $cc_encryption_hash from its configuration.php: a SHA-1 keystream XORed over the text,
 * with a random IV in front, base64 encoded.
 */
class WhmcsCrypt
{
    public function __construct(private string $hash) {}

    /**
     * The plain text, or null when the value isn't readable with this hash (wrong hash, not encrypted).
     *
     * Past the first 20 bytes the key is re-hashed with the previous block; versions of this routine differ on
     * whether that block is the plain text or the cipher text, so both are tried. A short password reads the same
     * either way; a wrong guess turns the rest of a long one into bytes that fail the check below.
     */
    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = base64_decode(trim($value), true);
        $hashKey = $this->hashKey();
        $length = strlen($hashKey);

        if ($raw === false || strlen($raw) <= $length) {
            return null;
        }

        $iv = substr($raw, 0, $length) ^ $hashKey;
        $cipher = substr($raw, $length);

        foreach ([false, true] as $chainCipher) {
            $key = $iv;
            $out = '';

            for ($c = 0; $c < strlen($cipher); $c++) {
                if ($c !== 0 && $c % $length === 0) {
                    $key = sha1($key.substr($chainCipher ? $cipher : $out, $c - $length, $length), true);
                }
                $out .= $key[$c % $length] ^ $cipher[$c];
            }

            // A wrong hash gives bytes, not a password.
            if (mb_check_encoding($out, 'UTF-8') && ! preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $out)) {
                return $out;
            }
        }

        return null;
    }

    /** The reverse of decrypt(), as WHMCS does it (used by the tests). */
    public function encrypt(string $value, ?string $iv = null, bool $chainCipher = false): string
    {
        $hashKey = $this->hashKey();
        $length = strlen($hashKey);
        $iv ??= sha1(random_bytes(16), true);

        $out = $iv ^ $hashKey;
        $key = $iv;

        for ($c = 0; $c < strlen($value); $c++) {
            if ($c !== 0 && $c % $length === 0) {
                $key = sha1($key.substr($chainCipher ? substr($out, $length) : $value, $c - $length, $length), true);
            }
            $out .= $key[$c % $length] ^ $value[$c];
        }

        return base64_encode($out);
    }

    private function hashKey(): string
    {
        return sha1(md5(md5($this->hash)).md5($this->hash), true);
    }
}
