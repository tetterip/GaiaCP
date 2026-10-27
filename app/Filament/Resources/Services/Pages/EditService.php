<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\BillingCycle;
use App\Filament\Actions\CertificateActions;
use App\Filament\Actions\ChangePriceAction;
use App\Filament\Actions\ChangeProductAction;
use App\Filament\Actions\DomainActions;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\ServiceLifecycleActions;
use App\Filament\Actions\TransferServiceAction;
use App\Filament\Resources\Services\ServiceResource;
use App\Invoices\RepriceIssuedNotice;
use App\Models\Product;
use App\Models\Service;
use App\Support\ProductOptions;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    /** The options picked, as the form's fields hold them, and what they add to the price. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['options'] = ProductOptions::values($this->getRecord()->options);
        $data['options_total'] = ProductOptions::total($this->getRecord()->options);

        return $data;
    }

    /** Picks left as they were keep their price then. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Service $service */
        $service = $this->getRecord();
        $product = filled($data['product_id'] ?? null) ? Product::find($data['product_id']) : null;
        $cycle = $data['billing_cycle'] ?? $service->billing_cycle;

        $data['options'] = ProductOptions::select(
            $product,
            (array) ($data['options'] ?? []),
            $cycle instanceof BillingCycle ? $cycle : BillingCycle::from($cycle),
            $data['currency'] ?? $service->currency,
            $cycle === $service->billing_cycle || $cycle === $service->billing_cycle->value ? $service->options : null,
        ) ?: null;

        // A first payment left as it was (a setup fee, a domain's registration price) moves with the price.
        if (array_key_exists('first_payment_cents', $data) && $service->first_payment_cents !== null
            && $data['first_payment_cents'] === $service->first_payment_cents && isset($data['price_cents'])) {
            $data['first_payment_cents'] = max(0, $service->first_payment_cents + (int) $data['price_cents'] - $service->price_cents);
        }

        return $data;
    }

    /** An unpaid notice already issued for its next due date (its order's, say) bills the new amount. */
    protected function afterSave(): void
    {
        if (app(RepriceIssuedNotice::class)->handle($this->getRecord()->refresh()) !== null) {
            Notification::make()->success()->title(__('The unpaid payment notice was updated to the new amount.'))->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make(),
            ServiceLifecycleActions::make(),
            DomainActions::make(),
            CertificateActions::make(),
            TransferServiceAction::make(),
            ActionGroup::make([
                ChangeProductAction::make(),
                ChangePriceAction::make(),
                ChangePriceAction::cancel(),
                RecordPaymentAction::early(),
            ])->label(__('Price'))->icon(Heroicon::OutlinedCurrencyEuro)->button()->color('gray'),
            // Services with payments keep their history; set them to Terminated or Cancelled instead.
            DeleteAction::make()
                ->hidden(fn (Service $record) => $record->payments()->exists()),
        ];
    }
}
