<?php

namespace App\Credit;

use App\Enums\CreditType;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Support\Currencies;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A client's credit balance (clients.credit_cents, in the client's currency) and its ledger (credit_transactions).
 * record() is the only code that changes a balance: it locks the client row, so two payments can't spend the same
 * credit, and never lets the balance go below zero.
 */
class ClientCredit
{
    /** payments.method of a payment made from credit. */
    public const METHOD = 'credit';

    public static function enabled(): bool
    {
        return (bool) config('gaiacp.credit.enabled');
    }

    public static function autoApply(): bool
    {
        return self::enabled() && (bool) config('gaiacp.credit.auto_apply', true);
    }

    /** A setting in base-currency units ("credit.max_deposit") in the client's currency, in cents. */
    public static function limit(string $key, Client $client): int
    {
        return Currencies::fromBase((int) round((float) config("gaiacp.credit.{$key}") * 100), $client->currencyCode());
    }

    /** Why this client can't add funds now, or null when they can. */
    public static function cannotAddFunds(Client $client): ?string
    {
        return match (true) {
            ! self::enabled() => __('Adding funds is not available.'),
            ! $client->services()->where('status', ServiceStatus::Active)->exists() => __('Funds can be added once you have an active service.'),
            self::maxDeposit($client) < self::limit('min_deposit', $client) => __('Your balance is at its maximum of :max.', ['max' => Money::format(self::limit('max_balance', $client), $client->currencyCode())]),
            default => null,
        };
    }

    /** The most one deposit can add now: the per-deposit maximum, or less to stay within the maximum balance. */
    public static function maxDeposit(Client $client): int
    {
        return max(0, min(self::limit('max_deposit', $client), self::limit('max_balance', $client) - (int) $client->fresh()->credit_cents));
    }

    /**
     * Adds (+) or takes off (-) credit and writes the ledger line.
     *
     * @param  array{invoice_id?: int|null, payment_id?: int|null, method?: string|null, reference?: string|null}  $links
     *
     * @throws RuntimeException when it would take the balance below zero
     */
    public function record(Client $client, CreditType $type, int $amountCents, string $description, array $links = []): CreditTransaction
    {
        if ($amountCents === 0) {
            throw new RuntimeException('A credit transaction needs an amount.');
        }

        $transaction = DB::transaction(function () use ($client, $type, $amountCents, $description, $links) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            $balance = (int) $locked->credit_cents + $amountCents;

            if ($balance < 0) {
                throw new RuntimeException(__('Not enough credit: the balance is :balance.', ['balance' => Money::format((int) $locked->credit_cents, $locked->currencyCode())]));
            }

            $locked->forceFill(['credit_cents' => $balance])->saveQuietly();

            return CreditTransaction::create([
                'client_id' => $client->id,
                'type' => $type,
                'amount_cents' => $amountCents,
                'balance_cents' => $balance,
                'currency' => $locked->currencyCode(),
                'description' => mb_substr($description, 0, 255),
                'invoice_id' => $links['invoice_id'] ?? null,
                'payment_id' => $links['payment_id'] ?? null,
                'method' => $links['method'] ?? null,
                'reference' => $links['reference'] ?? null,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);
        });

        $client->forceFill(['credit_cents' => $transaction->balance_cents])->syncOriginalAttribute('credit_cents');

        ActivityLog::record('credit.'.$type->value, sprintf(
            'Credit %s%s (%s); balance %s',
            $amountCents > 0 ? '+' : '-',
            Money::format(abs($amountCents), $transaction->currency),
            $description,
            Money::format($transaction->balance_cents, $transaction->currency),
        ), client: $client, properties: ['credit_transaction_id' => $transaction->id]);

        return $transaction;
    }
}
