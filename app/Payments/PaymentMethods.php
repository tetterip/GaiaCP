<?php

namespace App\Payments;

use App\Credit\ClientCredit;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;

/**
 * Every way a payment can be recorded: the payment modules of enabled plugins (PluginManager::paymentModules())
 * plus the built-in PaymentMethod cases (cash, other) for payments recorded by hand.
 */
class PaymentMethods
{
    /**
     * @param  string|null  $keep  the record's own method, kept even when its plugin is off
     * @return array<string, string> key => label, what a new payment can use
     */
    public static function options(?string $keep = null): array
    {
        $options = self::plugins()->paymentModules()->map(fn (PaymentModule $module) => $module->label())->all()
            + collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => $method->getLabel()])->all();

        if (filled($keep) && ! array_key_exists($keep, $options)) {
            $options[$keep] = self::label($keep);
        }

        return $options;
    }

    /** @return array<string, string> key => label of every method GaiaCP knows, enabled or not (filters) */
    public static function all(): array
    {
        return self::plugins()->all()
            ->flatMap(fn ($plugin) => $plugin->paymentModules())
            ->mapWithKeys(fn (PaymentModule $module) => [$module->key() => $module->label()])
            ->all()
            + collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => $method->getLabel()])->all()
            + [ClientCredit::METHOD => __('Account credit')];
    }

    /** A stored method's name; an unknown key (a removed plugin's) shows as itself. */
    public static function label(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        if ($key === ClientCredit::METHOD) {
            return __('Account credit'); // paid from the client's balance (App\Credit\ApplyCredit), never picked by hand
        }

        return self::plugins()->paymentModule($key)?->label() ?? PaymentMethod::tryFrom($key)?->getLabel() ?? $key;
    }

    /**
     * Pre-selected when recording a payment for a client without a default: the first enabled plugin's that is not
     * paid online (those record their own payments), else cash.
     */
    public static function default(): string
    {
        return self::plugins()->paymentModules()
            ->reject(fn (PaymentModule $module) => $module instanceof Plugin && $module->paysOnline())
            ->keys()->first() ?? PaymentMethod::Cash->value;
    }

    /** The method a payment of this client is recorded with when none is given: its default, else default(). */
    public static function defaultFor(?Client $client): string
    {
        $method = (string) $client?->default_payment_method;

        return array_key_exists($method, self::options()) ? $method : self::default();
    }

    /**
     * How to pay: the instructions of every enabled payment module, for {$payment_instructions} in emails. For one
     * payment notice, a module with an optional `instructionsFor(Invoice $invoice): ?string` gives those instead
     * (Bank transfer adds the notice number as the reference for the transfer). When the client area offers several
     * ways to pay a notice (one "Pay online" menu), the gateways' "press Pay by card" lines become one line about it.
     */
    public static function instructions(?Invoice $invoice = null): string
    {
        $several = count(self::plugins()->clientPaymentNoticeActions()) > 1;
        $online = __('Pay online in our client area: open the payment notice, press Pay online and choose how to pay.');

        return self::plugins()->paymentModules()
            ->map(fn (PaymentModule $module) => $several && $module instanceof Plugin && $module->paysOnline()
                ? $online
                : trim((string) ($invoice !== null && method_exists($module, 'instructionsFor') ? $module->instructionsFor($invoice) : $module->instructions())))
            ->filter()
            ->unique()
            ->implode("\n\n");
    }

    private static function plugins(): PluginManager
    {
        return app(PluginManager::class);
    }
}
