<?php

namespace Plugins\BankTransfer;

use App\Payments\PaymentModule;
use App\Plugins\Plugin;

/** Payment by bank transfer: the method you record, and the bank details (payment instructions) printed on payment notices. */
class BankTransferPlugin extends Plugin implements PaymentModule
{
    public function id(): string
    {
        return 'bank_transfer';
    }

    public function name(): string
    {
        return __('Bank transfer');
    }

    public function description(): string
    {
        return __('Clients pay into your bank account; its details are printed on every payment notice.');
    }

    public function category(): string
    {
        return 'Payments';
    }

    public function key(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return __('Bank transfer');
    }

    public function instructions(): ?string
    {
        $instructions = config('gaiacp.payment_instructions');

        return filled($instructions) ? (string) $instructions : null;
    }

    public function settingsPage(): ?string
    {
        return BankTransferSettings::class;
    }

    public function pages(): array
    {
        return [BankTransferSettings::class];
    }

    public function settingKeys(): array
    {
        return ['payment_instructions'];
    }
}
