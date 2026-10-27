<?php

namespace Plugins\Vies;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Plugins\PluginManager;
use App\Support\Locales;
use App\Support\ModuleLogger;
use App\Support\Region;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The EU's VIES service (REST API of ec.europa.eu): whether a VAT number is valid in its member state, with the
 * name and address when that state shares them. Each answer is kept (ViesCheck); exemption() turns a valid one into
 * the intra-EU reverse charge when Settings → VIES VAT check has it on.
 */
final class Vies
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /** EU member states: ISO country code => VIES code (Greece is EL in VIES). */
    public const MEMBER_STATES = [
        'AT' => 'AT', 'BE' => 'BE', 'BG' => 'BG', 'CY' => 'CY', 'CZ' => 'CZ', 'DE' => 'DE', 'DK' => 'DK',
        'EE' => 'EE', 'ES' => 'ES', 'FI' => 'FI', 'FR' => 'FR', 'GR' => 'EL', 'HR' => 'HR', 'HU' => 'HU',
        'IE' => 'IE', 'IT' => 'IT', 'LT' => 'LT', 'LU' => 'LU', 'LV' => 'LV', 'MT' => 'MT', 'NL' => 'NL',
        'PL' => 'PL', 'PT' => 'PT', 'RO' => 'RO', 'SE' => 'SE', 'SI' => 'SI', 'SK' => 'SK',
    ];

    public static function enabled(): bool
    {
        return app(PluginManager::class)->enabled('vies');
    }

    /** The VIES code of an EU country (GR → EL), null for any other country. */
    public static function memberState(?string $country): ?string
    {
        return self::MEMBER_STATES[strtoupper((string) $country)] ?? null;
    }

    /**
     * A VAT number as VIES wants it: no spaces, dots or dashes, upper case, without its country prefix.
     *
     * @return array{0: string, 1: string} [VIES code, number]
     *
     * @throws ViesException
     */
    public static function normalize(?string $number, ?string $country): array
    {
        $code = self::memberState($country);

        if ($code === null) {
            throw new ViesException(__('VIES only checks VAT numbers of EU countries: pick the client\'s EU country first.'));
        }

        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $number));

        foreach (array_unique([$code, strtoupper((string) $country)]) as $prefix) {
            if (str_starts_with($number, $prefix) && strlen($number) > strlen($prefix) + 1) {
                $number = substr($number, strlen($prefix));
                break;
            }
        }

        if ($number === '') {
            throw new ViesException(__('Enter the VAT number first.'));
        }

        return [$code, $number];
    }

    /**
     * Asks VIES and keeps its answer.
     *
     * @throws ViesException when VIES could not say (the number's validity is then unknown, nothing is kept)
     */
    public static function check(?string $number, ?string $country): ViesCheck
    {
        [$code, $number] = self::normalize($number, $country);

        try {
            $answer = self::call($code, $number);
        } catch (ViesException $e) {
            ActivityLog::record('vies.failed', "VIES check of {$code}{$number} failed: {$e->getMessage()}");

            throw $e;
        }

        $check = ViesCheck::create([
            'country_code' => $code,
            'vat_number' => $number,
            'valid' => (bool) $answer['valid'],
            'name' => self::text($answer['name'] ?? null),
            'address' => self::text($answer['address'] ?? null),
            'request_identifier' => self::text($answer['requestIdentifier'] ?? null),
            'user_id' => auth()->id(),
            'checked_at' => now(),
        ]);

        ActivityLog::record('vies.checked', "VIES check of {$code}{$number}: ".($check->valid ? 'valid' : 'not valid'), properties: [
            'vies_check_id' => $check->id,
            'valid' => $check->valid,
            'request_identifier' => $check->request_identifier,
        ]);

        return $check;
    }

    /** The latest check of the client's VAT number as it is now, null when it was never checked. */
    public static function latestFor(Client $client): ?ViesCheck
    {
        try {
            [$code, $number] = self::normalize($client->vat_number, $client->country);
        } catch (ViesException) {
            return null;
        }

        return ViesCheck::query()
            ->where('country_code', $code)
            ->where('vat_number', $number)
            ->latest('checked_at')
            ->latest('id')
            ->first();
    }

    /** Your EU country: Settings → VIES VAT check, else Settings → General → Region. */
    public static function homeCountry(): ?string
    {
        return strtoupper((string) (config('gaiacp.vies.home_country') ?: Region::country())) ?: null;
    }

    /**
     * The reverse charge note for an EU business in another EU country whose VAT number VIES found valid within
     * `valid_days`, null otherwise (Plugin::taxExemption()).
     */
    public static function exemption(Client $client): ?string
    {
        $home = self::homeCountry();

        if (! config('gaiacp.vies.reverse_charge') || self::memberState($home) === null
            || self::memberState($client->country) === null || strtoupper((string) $client->country) === $home) {
            return null;
        }

        $check = self::latestFor($client);
        $days = max(1, (int) config('gaiacp.vies.valid_days', 365));

        if (! $check?->valid || $check->checked_at->lt(now()->subDays($days))) {
            return null;
        }

        return filled(config('gaiacp.vies.reverse_charge_note'))
            ? (string) config('gaiacp.vies.reverse_charge_note')
            : Locales::using($client->preferredLocale(), fn () => self::defaultNote());
    }

    public static function defaultNote(): string
    {
        return __('Reverse charge: VAT to be accounted for by the recipient (Article 196, Directive 2006/112/EC).');
    }

    /** @return array<string, mixed> VIES's answer */
    private static function call(string $code, string $number): array
    {
        $body = ['countryCode' => $code, 'vatNumber' => $number];
        $requester = self::requester();

        if ($requester !== null) {
            $body += ['requesterMemberStateCode' => $requester[0], 'requesterNumber' => $requester[1]];
        }

        try {
            $response = Http::acceptJson()
                ->withMiddleware(ModuleLogger::middleware('vies', 'check-vat-number'))
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            $reason = preg_replace('/\s*\(see https:\/\/curl\.se.*$/s', '', $e->getMessage());

            throw new ViesException(__('Cannot reach VIES: :reason', ['reason' => $reason]), previous: $e);
        }

        $json = $response->json();
        $error = is_array($json) ? ($json['errorWrappers'][0]['error'] ?? null) : null;

        if ($error !== null || ($json['actionSucceed'] ?? true) === false) {
            throw new ViesException(self::errorMessage($error, $response->status()));
        }

        if (! $response->successful() || ! is_array($json) || ! array_key_exists('valid', $json)) {
            throw new ViesException(__('VIES gave an answer GaiaCP does not understand (HTTP :status).', ['status' => $response->status()]));
        }

        return $json;
    }

    /** @return array{0: string, 1: string}|null your VIES code and VAT number, when both are known */
    private static function requester(): ?array
    {
        $vat = config('gaiacp.vies.requester_vat') ?: config('gaiacp.company.vat_number');

        try {
            return filled($vat) ? self::normalize($vat, self::homeCountry()) : null;
        } catch (ViesException) {
            return null;
        }
    }

    /** What VIES's error code means, for the admin. */
    private static function errorMessage(?string $code, int $status): string
    {
        return match ($code) {
            'INVALID_INPUT' => __('VIES says the country or VAT number is not in a valid form.'),
            'INVALID_REQUESTER_INFO' => __('VIES refused your own VAT number (Settings → VIES VAT check).'),
            'MS_UNAVAILABLE' => __('The member state\'s VAT service is not answering right now. Try again later.'),
            'MS_MAX_CONCURRENT_REQ' => __('The member state\'s VAT service is busy. Try again in a minute.'),
            'GLOBAL_MAX_CONCURRENT_REQ' => __('VIES is busy. Try again in a minute.'),
            'SERVICE_UNAVAILABLE' => __('VIES is not answering right now. Try again later.'),
            'TIMEOUT' => __('The member state\'s VAT service did not answer in time. Try again later.'),
            'VAT_BLOCKED' => __('VIES does not check this VAT number.'),
            'IP_BLOCKED' => __('VIES refuses requests from this server\'s address.'),
            default => __('VIES could not check the number (:code).', ['code' => $code ?? $status]),
        };
    }

    /** VIES writes "---" for what a member state doesn't share. */
    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '---' ? null : $value;
    }
}
