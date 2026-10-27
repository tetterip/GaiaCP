<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies as IntlCurrencies;

/**
 * The business's region (Settings → General → Region, asked by the installer): its timezone (due dates, the daily
 * run), how dates are written and the country new clients start in. Defaults in config('gaiacp.region').
 */
final class Region
{
    /** The date formats offered: day first, month first and ISO. */
    public const DATE_FORMATS = ['d/m/Y', 'd.m.Y', 'd-m-Y', 'm/d/Y', 'Y-m-d'];

    public static function timezone(): string
    {
        $zone = (string) config('gaiacp.region.timezone');

        return self::validTimezone($zone) ? $zone : 'UTC';
    }

    public static function validTimezone(?string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true) || $zone === 'UTC';
    }

    /** Points the app and PHP at the region's timezone (Settings::apply, after the setting was loaded). */
    public static function applyTimezone(): void
    {
        $zone = self::timezone();
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    public static function dateFormat(): string
    {
        $format = (string) config('gaiacp.region.date_format');

        return in_array($format, self::DATE_FORMATS, true) ? $format : 'd/m/Y';
    }

    /** The date format without the year, for chart labels: "d/m", "m/d", "m-d". */
    public static function shortDateFormat(): string
    {
        return trim(str_replace('Y', '', self::dateFormat()), '/.-');
    }

    /** The country new clients start in (ISO code), null when not set. */
    public static function country(): ?string
    {
        $country = strtoupper((string) config('gaiacp.region.country'));

        return $country !== '' && Countries::exists($country) ? $country : null;
    }

    /** @return array<string, string> zone => "Europe/Athens (UTC+03:00)" */
    public static function timezoneOptions(): array
    {
        $now = CarbonImmutable::now('UTC');

        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $zone) => [$zone => $zone.' (UTC'.$now->setTimezone($zone)->format('P').')'])
            ->all();
    }

    /** @return array<string, string> format => today written in it */
    public static function dateFormatOptions(): array
    {
        $today = CarbonImmutable::today();

        return collect(self::DATE_FORMATS)->mapWithKeys(fn (string $format) => [$format => $today->format($format)])->all();
    }

    /** @return array<string, string> code => "EUR · Euro" */
    public static function currencyOptions(): array
    {
        return collect(IntlCurrencies::getNames(app()->getLocale()))
            ->filter(fn (string $name, string $code) => IntlCurrencies::getFractionDigits($code) === 2) // cents
            ->mapWithKeys(fn (string $name, string $code) => [$code => "{$code} · {$name}"])
            ->all();
    }
}
