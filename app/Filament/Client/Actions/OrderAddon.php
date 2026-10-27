<?php

namespace App\Filament\Client\Actions;

use App\Actions\ClientAddons;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The client's "Add an add-on" on a service (App\Actions\ClientAddons): its add-ons with their price for the service's
 * cycle and what is owed now, until the service's next due date. With something to pay, it opens the payment notice.
 */
class OrderAddon
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::order()];
    }

    private static function order(): Action
    {
        return Action::make('orderAddon')
            ->label('Add an add-on')
            ->icon(Heroicon::OutlinedPuzzlePiece)
            ->color('primary')
            ->outlined()
            ->visible(fn (Service $record) => ClientAddons::canAdd($record))
            ->modalHeading(fn (Service $record) => __('Add an add-on to :service', ['service' => $record->domain ?: $record->label]))
            ->modalDescription(fn (Service $record) => __('It renews together with the service, on :date. Now you pay only for the days until then.', [
                'date' => $record->next_due_date->format(AppServiceProvider::dateFormat()),
            ]))
            ->schema(fn (Service $record) => [
                Radio::make('product_id')
                    ->label('Add-on')
                    ->options(ClientAddons::products($record)->mapWithKeys(fn (Product $product) => [
                        $product->id => __(':product: :price :cycle', [
                            'product' => $product->name,
                            'price' => Money::format((int) $product->priceFor($record->billing_cycle, $record->currency), $record->currency),
                            'cycle' => $record->billing_cycle->getLabel(),
                        ]),
                    ])->all())
                    ->descriptions(ClientAddons::products($record)->mapWithKeys(fn (Product $product) => [
                        $product->id => self::due($record, $product),
                    ])->all())
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Add'))
            ->action(function (Service $record, array $data, Action $action) {
                $result = app(ClientAddons::class)->order($record, Product::findOrFail($data['product_id']));

                if ($result['invoice'] === null) {
                    Notification::make()->title(__('Add-on added'))->success()->send();

                    return;
                }

                Notification::make()->title(__('Add-on added'))->body(__('It is active once the payment notice is paid.'))->success()->send();
                $action->redirect(PaymentNoticeResource::getUrl('view', ['record' => $result['invoice']]));
            });
    }

    /** "€2.50 now until 01/12/2026, and €10.00 setup fee" */
    private static function due(Service $service, Product $addon): string
    {
        $quote = ClientAddons::quote($service, $addon);

        if ($quote['total'] === 0) {
            return __('Nothing to pay now');
        }

        $now = __(':amount now until :date', [
            'amount' => Money::format($quote['days'], $service->currency),
            'date' => $service->next_due_date->format(AppServiceProvider::dateFormat()),
        ]);

        return $quote['setup'] > 0
            ? __(':now, and :setup setup fee', ['now' => $now, 'setup' => Money::format($quote['setup'], $service->currency)])
            : $now;
    }

    /** The service's add-ons, those waiting for their payment notice linked to it. */
    public static function list(Service $service): HtmlString
    {
        $lines = $service->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->orderBy('label')->get()
            ->map(function (Service $addon) {
                $text = e(__(':addon: :price / :cycle (:status)', [
                    'addon' => $addon->label,
                    'price' => Money::format($addon->price_cents, $addon->currency),
                    'cycle' => $addon->billing_cycle->getLabel(),
                    'status' => $addon->status->getLabel(),
                ]));

                $notice = $addon->status === ServiceStatus::Pending
                    ? Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereHas('items', fn ($q) => $q->where('service_id', $addon->id))->latest('id')->first()
                    : null;

                return $notice === null ? $text : $text.' · <a href="'.e(PaymentNoticeResource::getUrl('view', ['record' => $notice])).'">'
                    .e(__('Pay payment notice :number', ['number' => $notice->number])).'</a>';
            });

        return new HtmlString($lines->implode('<br>'));
    }
}
