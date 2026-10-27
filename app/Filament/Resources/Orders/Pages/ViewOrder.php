<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\CancelOrder;
use App\Actions\CompleteOrder;
use App\Actions\RecordOrderPayment;
use App\Enums\AutoSetup;
use App\Enums\DomainRegistrationType;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Forms\PackageSelect;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Payments\PaymentMethods;
use App\Provisioning\ProvisioningException;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return "Order #{$this->getRecord()->getKey()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->recordPayment(),
            $this->tax(),
            $this->complete(),
            $this->cancel(),
        ];
    }

    private function complete(): Action
    {
        return Action::make('completeOrder')
            ->label('Complete order')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->visible(fn (Order $record) => $record->status === OrderStatus::Pending)
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->modalHeading(__('Complete order'))
            ->modalDescription(fn (Order $record) => self::pending($record)
                ->map(fn (Service $service) => self::plan($service))
                ->join(' ').' '.__('Registrations and transfers charge your registrar account.'))
            // The registrar of each domain and the server of each hosting account, changeable here.
            ->schema(fn (Order $record) => self::pending($record)->filter(fn (Service $service) => self::choosable($service))
                ->map(fn (Service $service) => self::choiceFields($service))->values()->all())
            ->fillForm(fn (Order $record) => ['items' => self::pending($record)->filter(fn (Service $service) => self::choosable($service))
                ->mapWithKeys(fn (Service $service) => [$service->id => $service->type === ServiceType::Domain
                    ? ['registrar_id' => $service->registration()->registrar_id]
                    : ['server_id' => $service->server_id ?? $service->product?->pickServer()?->id, 'package' => $service->package]])
                ->all()])
            ->action(function (Order $record, array $data) {
                try {
                    app(CompleteOrder::class)->assign($record, $data['items'] ?? []);
                    $failures = app(CompleteOrder::class)->handle($record);
                } catch (ProvisioningException $e) {
                    Notification::make()->title(__('Failed'))->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                if ($failures === []) {
                    Notification::make()->title(__('Order completed'))->success()->send();
                } else {
                    $labels = Service::query()->whereKey(array_keys($failures))->pluck('label', 'id');
                    Notification::make()
                        ->title(__('Some items are still pending'))
                        ->body(collect($failures)->map(fn (string $error, int $id) => "{$labels[$id]}: ".Str::finish(rtrim($error), '.'))->join("\n").' '.__('Fix them and run "Complete order" again; finished items are skipped.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }

                $this->refreshFormData(['status']);
            });
    }

    private function recordPayment(): Action
    {
        return Action::make('recordOrderPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Order $record) => $record->status !== OrderStatus::Cancelled && app(RecordOrderPayment::class)->unpaid($record)->isNotEmpty())
            ->modalDescription(function (Order $record) {
                $unpaid = app(RecordOrderPayment::class)->unpaid($record);

                return __('Records the first payment of :count item(s), :total in total. Each due date moves forward one cycle.', [
                    'count' => $unpaid->count(),
                    'total' => Money::format($unpaid->sum(fn (Service $s) => $s->firstPaymentCents()), $record->currency()),
                ]);
            })
            ->schema([
                DatePicker::make('paid_on')
                    ->required()
                    ->default(now())
                    ->maxDate(now()),
                Select::make('method')
                    ->options(fn () => PaymentMethods::options())
                    ->default(fn (Order $record) => array_key_exists((string) $record->client->default_payment_method, PaymentMethods::options()) ? $record->client->default_payment_method : PaymentMethods::default())
                    ->required(),
                TextInput::make('reference')
                    ->maxLength(255),
            ])
            ->action(function (Order $record, array $data) {
                $recorder = app(RecordOrderPayment::class);
                $payments = $recorder->handle(
                    $record,
                    CarbonImmutable::parse($data['paid_on']),
                    $data['method'],
                    $data['reference'] ?? null,
                );

                Notification::make()->title(__(':count payment(s) recorded', ['count' => count($payments)]))->success()->send();

                foreach ($recorder->setupErrors as $item => $error) {
                    Notification::make()
                        ->title(__(':item was not set up', ['item' => $item]))
                        ->body($error.' '.__('The payment is recorded; complete the order to retry.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Takes the tax off the order's unpaid notice (optionally making the client tax exempt from now on), or puts the
     * client's tax rule back on it.
     */
    private function tax(): Action
    {
        $notice = fn (Order $record): ?Invoice => $record->status === OrderStatus::Cancelled ? null : $record->invoices()->unpaid()->latest('id')->first();

        return Action::make('orderTax')
            ->label(fn (Order $record) => $notice($record)?->tax_rate ? __('Without tax') : __('Add tax'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('gray')
            ->visible(fn (Order $record) => ($invoice = $notice($record)) !== null && ($invoice->tax_rate || Tax::ruleFor($record->client) !== null))
            ->modalHeading(fn (Order $record) => $notice($record)?->tax_rate ? __('Without tax') : __('Add tax'))
            ->modalDescription(fn (Order $record) => $notice($record)?->tax_rate
                ? __('Takes the tax off payment notice :number, so the order is owed without it. Renewals are taxed as usual unless the client is tax exempt.', ['number' => $notice($record)->number])
                : __('Puts the client\'s tax rule back on payment notice :number.', ['number' => $notice($record)?->number]))
            ->schema(fn (Order $record) => $notice($record)?->tax_rate && ! $record->client->tax_exempt ? [
                Toggle::make('client_exempt')
                    ->label('Make the client tax exempt from now on'),
            ] : [])
            ->action(function (Order $record, array $data) use ($notice) {
                $invoice = $notice($record);

                if ($invoice === null) {
                    return;
                }

                if ($invoice->tax_rate) {
                    if ($data['client_exempt'] ?? false) {
                        $record->client->update(['tax_exempt' => true]);
                        ActivityLog::record('client.tax_exempt', "Client made tax exempt from order #{$record->id}", client: $record->client);
                    }

                    $invoice->retax(null, false);
                    ActivityLog::record('invoice.tax_removed', "Payment notice {$invoice->number} of order #{$record->id}: tax taken off", client: $record->client, properties: ['invoice_id' => $invoice->id]);
                    Notification::make()->title(__('Tax taken off payment notice :number', ['number' => $invoice->number]))->success()->send();
                } else {
                    $invoice->retax(Tax::ruleFor($record->client), Tax::inclusive());
                    ActivityLog::record('invoice.tax_added', "Payment notice {$invoice->number} of order #{$record->id}: tax put back", client: $record->client, properties: ['invoice_id' => $invoice->id]);
                    Notification::make()->title(__('Tax added to payment notice :number', ['number' => $invoice->number]))->success()->send();
                }
            });
    }

    private function cancel(): Action
    {
        return Action::make('cancelOrder')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            // Once something is active it has to be terminated item by item.
            ->visible(fn (Order $record) => CancelOrder::canCancel($record))
            ->requiresConfirmation()
            ->modalDescription(__('Marks the order, all its items and its unpaid payment notice cancelled. Nothing was created on a server or registrar yet.'))
            ->action(function (Order $record) {
                app(CancelOrder::class)->handle($record);

                Notification::make()->title(__('Order cancelled'))->success()->send();
                $this->refreshFormData(['status']);
            });
    }

    /** @return Collection<int, Service> */
    private static function pending(Order $order)
    {
        return $order->services()->with('server', 'domainDetails')->where('status', ServiceStatus::Pending)->orderBy('id')->get();
    }

    /** A pending item that "Complete order" sets up at a registrar or on a server. */
    private static function choosable(Service $service): bool
    {
        return $service->auto_setup !== AutoSetup::Never && match (true) {
            $service->type === ServiceType::Domain => $service->registration()->registration_type !== DomainRegistrationType::External,
            default => $service->type->needsServer(),
        };
    }

    private static function choiceFields(Service $service): Fieldset
    {
        $fields = $service->type === ServiceType::Domain
            ? [
                Select::make('registrar_id')
                    ->label('Registrar')
                    ->options(fn () => Registrar::query()
                        ->where(fn ($query) => $query->where('active', true)->orWhereKey($service->registration()->registrar_id))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->placeholder(__('The TLD\'s preferred registrar (:registrar)', [
                        'registrar' => Registrar::forDomain((string) $service->domain)->name ?? __('no active registrar'),
                    ])),
            ]
            : [
                Select::make('server_id')
                    ->label('Server')
                    // Each with its accounts against its maximum ("Server 1 · accounts: 33/120 (28%)")
                    ->options(fn () => Server::query()
                        ->where(fn ($query) => $query->where('active', true)->orWhereKey($service->server_id))
                        ->withAccountsCount()->orderBy('name')->get()
                        ->mapWithKeys(fn (Server $server) => [$server->id => $server->optionLabel()]))
                    ->required()
                    ->live(),
                PackageSelect::make(fn (Get $get) => $get('server_id')),
            ];

        return Fieldset::make($service->type === ServiceType::Domain ? (string) $service->domain : "{$service->label} · {$service->domain}")
            ->statePath("items.{$service->id}")
            ->columns(1)
            ->schema($fields);
    }

    /** One sentence on what "Complete order" does with a pending item. */
    private static function plan(Service $service): string
    {
        return match (true) {
            $service->type === ServiceType::Domain => match ($service->registration()->registration_type) {
                DomainRegistrationType::Register => __('Registers :domain for :years year(s).', ['domain' => $service->domain, 'years' => $service->registrationYears()]),
                DomainRegistrationType::Transfer => __('Requests the transfer of :domain.', ['domain' => $service->domain]),
                DomainRegistrationType::External => __('Marks :domain active.', ['domain' => $service->domain]),
            },
            $service->type->needsServer() && self::choosable($service) => __('Creates :domain on the server chosen below.', ['domain' => $service->domain]),
            $service->type->needsServer() => __('Creates :domain on :server.', ['domain' => $service->domain, 'server' => $service->server->name ?? __('(no server!)')]),
            default => __('Activates :service.', ['service' => $service->label]),
        };
    }
}
