<?php

namespace App\Filament\Resources\UpgradeRequests\Tables;

use App\Actions\ChangeProduct;
use App\Actions\UpgradeRequests;
use App\Enums\ChangeRequestStatus;
use App\Filament\Actions\ChangeProductAction;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\UpgradeRequest;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/** Date, service and client, from and to, the price asked; Approve (with "charge the difference now") or Decline. */
class UpgradeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service.product', 'client', 'product', 'decidedBy'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('service.label')
                    ->label('Service')
                    ->formatStateUsing(fn (UpgradeRequest $record) => $record->serviceName())
                    ->description(fn (UpgradeRequest $record) => $record->client->name)
                    ->url(fn (UpgradeRequest $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]))
                    ->searchable(['label', 'domain'])
                    ->wrap(),
                TextColumn::make('product.name')
                    ->label('Asked for')
                    ->description(fn (UpgradeRequest $record) => __('From :product', ['product' => $record->service->product?->name ?? '-']))
                    ->wrap(),
                TextColumn::make('price_cents')
                    ->label('Price per cycle')
                    ->formatStateUsing(fn (UpgradeRequest $record) => Money::format($record->price_cents, $record->service->currency))
                    ->description(fn (UpgradeRequest $record) => __('Now :price', ['price' => Money::format($record->service->price_cents, $record->service->currency)])),
                TextColumn::make('notes')
                    ->placeholder('–')
                    ->limit(120)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (UpgradeRequest $record) => $record->decided_at
                        ? trim($record->decided_at->format(AppServiceProvider::dateFormat()).' '.$record->decidedBy?->name)
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (UpgradeRequest $record) => $record->status === ChangeRequestStatus::Pending)
                    ->modalHeading(fn (UpgradeRequest $record) => __('Move :service to :product?', ['service' => $record->serviceName(), 'product' => $record->product->name]))
                    ->modalDescription(fn (UpgradeRequest $record) => __('The new package is set on the server now. The new price is owed from the next due date (:date); a payment notice already issued for it is updated.', [
                        'date' => $record->service->next_due_date->format(AppServiceProvider::dateFormat()),
                    ]).' '.__('The client gets the Upgrade Request Approved email, if it is on.'))
                    ->fillForm(fn (UpgradeRequest $record) => [
                        'price' => Money::toDecimal(ChangeProduct::price($record->service, $record->product) ?? $record->price_cents),
                        'charge_now' => false,
                    ])
                    ->schema(fn (UpgradeRequest $record) => [
                        TextInput::make('price')
                            ->label(fn () => __('Price per cycle (:cycle)', ['cycle' => $record->service->billing_cycle->getLabel()]))
                            ->prefix($record->service->currency)
                            ->required()
                            ->live(onBlur: true)
                            ->rules(['regex:/^\d+([.,]\d{1,2})?$/'])
                            ->helperText(__('The client was shown :price.', ['price' => Money::format($record->price_cents, $record->service->currency)])),
                        Toggle::make('charge_now')
                            ->label('Charge the difference now')
                            ->live()
                            ->helperText(fn (Get $get) => ChangeProductAction::differenceText($record->service, $get('price'))),
                    ])
                    ->modalSubmitActionLabel(__('Approve'))
                    ->action(function (UpgradeRequest $record, array $data, Action $action) {
                        try {
                            $result = app(UpgradeRequests::class)->approve($record, Money::toCents($data['price']), (bool) $data['charge_now']);
                        } catch (ProvisioningException|InvalidArgumentException $e) {
                            Notification::make()->title(__('Not changed'))->body($e->getMessage())->danger()->persistent()->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()
                            ->title(__('Request approved'))
                            ->body(ChangeProductAction::resultText($result, $record->service->currency))
                            ->success()
                            ->send();
                    }),
                Action::make('decline')
                    ->label('Decline')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (UpgradeRequest $record) => $record->status === ChangeRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription(__('The service stays as it is. The client gets the Upgrade Request Declined email, if it is on.'))
                    ->modalSubmitActionLabel(__('Decline'))
                    ->action(function (UpgradeRequest $record) {
                        app(UpgradeRequests::class)->reject($record);
                        Notification::make()->title(__('Request declined'))->success()->send();
                    }),
            ]);
    }
}
