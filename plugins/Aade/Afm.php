<?php

namespace Plugins\Aade;

/** Greek tax number (ΑΦΜ): nine digits, the last a check digit. */
class Afm
{
    /** Digits only, so "EL 123 456 789" becomes "123456789". */
    public static function clean(?string $afm): string
    {
        return preg_replace('/\D/', '', (string) $afm);
    }

    public static function isValid(string $afm): bool
    {
        if (! preg_match('/^\d{9}$/', $afm) || $afm === '000000000') {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $afm[$i] * (2 ** (8 - $i));
        }

        return ($sum % 11) % 10 === (int) $afm[8];
    }
}
