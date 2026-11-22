<?php

namespace App\Auth;

use SensitiveParameter;

/**
 * Password hashes made by another system, kept in users.imported_password until the user's first login (WHMCS import):
 * PHP's password_hash() (bcrypt, Argon2, what WHMCS 6 and later store), and the salted MD5 of older WHMCS versions
 * (`md5(salt.password):salt`) or a bare MD5. Nothing here is ever used to make a hash: a matching login replaces it with
 * GaiaCP's own (UserProvider).
 */
class ImportedPassword
{
    /** Whether this is a hash in a format this class can check. */
    public static function supported(?string $hash): bool
    {
        $hash = trim((string) $hash);

        return self::isPhpHash($hash)
            || preg_match('/^[a-f0-9]{32}:.+$/i', $hash) === 1
            || preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    }

    public static function check(#[SensitiveParameter] string $password, ?string $hash): bool
    {
        $hash = trim((string) $hash);

        if ($password === '' || ! self::supported($hash)) {
            return false;
        }

        if (self::isPhpHash($hash)) {
            return password_verify($password, $hash);
        }

        if (str_contains($hash, ':')) {
            [$md5, $salt] = explode(':', $hash, 2);

            return hash_equals(strtolower($md5), md5($salt.$password));
        }

        return hash_equals(strtolower($hash), md5($password));
    }

    private static function isPhpHash(string $hash): bool
    {
        return password_get_info($hash)['algo'] !== null;
    }
}
