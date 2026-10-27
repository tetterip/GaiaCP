<?php

namespace App\Support;

/**
 * Phone numbers in the EPP format registrars take: "+30.2101234567" (calling code, a dot, the number). A number
 * without a country code is taken as the client's country's (else Settings → General → Country's), without its
 * leading trunk 0. Used by the registrar plugins (easy.gr, Enom).
 */
final class EppPhone
{
    /** Calling codes by ISO country, also tried longest first to put the "." in "+CC.NUMBER". */
    private const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AL' => '355', 'AM' => '374', 'AR' => '54', 'AT' => '43', 'AU' => '61', 'AZ' => '994',
        'BA' => '387', 'BE' => '32', 'BG' => '359', 'BR' => '55', 'BY' => '375', 'CA' => '1', 'CH' => '41', 'CL' => '56',
        'CN' => '86', 'CO' => '57', 'CY' => '357', 'CZ' => '420', 'DE' => '49', 'DK' => '45', 'DZ' => '213', 'EE' => '372',
        'EG' => '20', 'ES' => '34', 'FI' => '358', 'FR' => '33', 'GB' => '44', 'GE' => '995', 'GR' => '30', 'HK' => '852',
        'HR' => '385', 'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972', 'IN' => '91', 'IS' => '354', 'IT' => '39',
        'JP' => '81', 'KR' => '82', 'KZ' => '7', 'LB' => '961', 'LI' => '423', 'LT' => '370', 'LU' => '352', 'LV' => '371',
        'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382', 'MK' => '389', 'MT' => '356', 'MX' => '52', 'MY' => '60',
        'NG' => '234', 'NL' => '31', 'NO' => '47', 'NZ' => '64', 'PH' => '63', 'PK' => '92', 'PL' => '48', 'PT' => '351',
        'RO' => '40', 'RS' => '381', 'RU' => '7', 'SA' => '966', 'SE' => '46', 'SG' => '65', 'SI' => '386', 'SK' => '421',
        'SM' => '378', 'TH' => '66', 'TN' => '216', 'TR' => '90', 'TW' => '886', 'UA' => '380', 'US' => '1', 'VA' => '39',
        'VN' => '84', 'ZA' => '27',
    ];

    /** Countries whose numbers keep their leading 0 after the calling code. */
    private const KEEPS_ZERO = ['IT', 'SM', 'VA'];

    public static function format(?string $phone, ?string $country = null): string
    {
        $phone = preg_replace('/[\s\-()\/]/', '', (string) $phone);

        if ($phone === '') {
            return '';
        }

        if (preg_match('/^\+\d{1,3}\.\d+$/', $phone)) {
            return $phone;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($digits, 2);
            $digits = substr($digits, 2);
        }

        if (! str_starts_with($phone, '+')) {
            $country = strtoupper((string) ($country ?: Region::country()));
            $code = self::CALLING_CODES[$country] ?? null;

            if ($code === null) {
                return self::split($digits); // no country to go by: read it as written with its code
            }

            if (strlen($digits) === strlen($code) + 10 && str_starts_with($digits, $code)) {
                $digits = substr($digits, strlen($code)); // the code without "+": 302101234567
            } elseif (str_starts_with($digits, '0') && ! in_array($country, self::KEEPS_ZERO, true)) {
                $digits = substr($digits, 1); // the trunk 0 of a national number: 020 7946 0018
            }

            return "+{$code}.{$digits}";
        }

        return self::split($digits);
    }

    private static function split(string $digits): string
    {
        $codes = array_unique(array_values(self::CALLING_CODES));
        usort($codes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($codes as $code) {
            if (str_starts_with($digits, $code)) {
                return "+{$code}.".substr($digits, strlen($code));
            }
        }

        return '+'.substr($digits, 0, 2).'.'.substr($digits, 2);
    }
}
