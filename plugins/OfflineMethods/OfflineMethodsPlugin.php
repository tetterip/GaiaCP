<?php

namespace Plugins\OfflineMethods;

use App\Plugins\Plugin;
use Illuminate\Support\Str;

/**
 * Ways to pay you set up yourself, without a payment gateway (like WHMCS's Mail In): a cheque, cash at the office, IRIS
 * to a phone number, Revolut or Wise. Each is a payment method of its own: its instructions show with the payment
 * notices, and an admin records the payment by hand with it.
 */
class OfflineMethodsPlugin extends Plugin
{
    public function id(): string
    {
        return 'offline-methods';
    }

    public function name(): string
    {
        return __('Other ways to pay');
    }

    public function description(): string
    {
        return __('Your own ways to pay without a gateway (cheque, cash at the office, IRIS to a phone number, Revolut), each with instructions shown on payment notices.');
    }

    public function category(): string
    {
        return 'Payments';
    }

    /** Off in a new install: switched on under Settings → Payment methods once you have one to add. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    /** @return list<OfflineMethod> the active ones */
    public function paymentModules(): array
    {
        return collect(self::methods())
            ->filter(fn (array $method) => $method['active'])
            ->map(fn (array $method) => new OfflineMethod($method['key'], $method['name'], $method['instructions']))
            ->values()
            ->all();
    }

    /** @return list<array{key: string, name: string, instructions: ?string, active: bool}> as saved, with a key each */
    public static function methods(): array
    {
        return collect((array) config('gaiacp.offline_methods.methods'))
            ->filter(fn ($method) => is_array($method) && filled($method['key'] ?? null) && filled($method['name'] ?? null))
            ->map(fn (array $method) => [
                'key' => (string) $method['key'],
                'name' => (string) $method['name'],
                'instructions' => filled($method['instructions'] ?? null) ? (string) $method['instructions'] : null,
                'active' => (bool) ($method['active'] ?? true),
            ])
            ->values()
            ->all();
    }

    /**
     * A new method's key from its name ("Cash at the office" → offline_cash_at_the_office), never one already taken.
     *
     * @param  list<string>  $taken
     */
    public static function keyFor(string $name, array $taken): string
    {
        $base = 'offline_'.(Str::limit(Str::slug(Str::ascii($name), '_'), 30, '') ?: 'method');
        $key = $base;

        for ($i = 2; in_array($key, $taken, true); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }

    public function settingsPage(): ?string
    {
        return OfflineMethodsSettings::class;
    }

    public function pages(): array
    {
        return [OfflineMethodsSettings::class];
    }

    public function settingKeys(): array
    {
        return ['offline_methods.methods'];
    }
}
