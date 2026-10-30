<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Actions\ChangePriceAction;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\ProductTag;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServicesTable
{
    /** The statuses listed until the status filter says otherwise: what is running or about to. */
    public const OPEN = [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending];

    /** "Due soon" on the list's quick filter and in the Next due column. */
    public const DUE_SOON_DAYS = 14;

    /** @param ?string $tab a ListServices::TABS key when the table shows only that kind (the client page's tabs), else the list's own tab */
    public static function configure(Table $table, bool $showClient = true, ?string $tab = null): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'domainDetails', 'product', 'server']))
            ->columns([
                TextColumn::make('label')
                    ->label('Service')
                    ->formatStateUsing(fn (Service $record) => $record->domain ?: $record->label)
                    ->description(fn (Service $record) => self::serviceDetail($record))
                    ->weight('semibold')
                    ->searchable(['label', 'domain', 'username']),
                TextColumn::make('client.last_name')
                    ->label('Client')
                    ->formatStateUsing(fn (Service $record) => $record->client->name)
                    ->description(fn (Service $record) => $record->client->company)
                    ->searchable(['first_name', 'last_name', 'company'])
                    ->sortable()
                    ->visible($showClient),
                TextColumn::make('type')
                    ->badge()
                    ->visible(fn ($livewire) => ! in_array($tab ?? $livewire->activeTab ?? null, array_keys(ListServices::TABS), true)),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('server.name')
                    ->placeholder('—')
                    ->description(fn (Service $record) => $record->server ? app(PluginManager::class)->serverModule($record->server->type)?->label() : null)
                    ->toggleable()
                    ->visible(fn ($livewire) => ! in_array($tab ?? $livewire->activeTab ?? null, ['domains', 'other'], true)),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->formatStateUsing(fn (Service $record) => Money::format($record->price_cents, $record->currency))
                    ->description(fn (Service $record) => $record->new_price_from
                        ? __(':cycle · from :date :price', ['cycle' => $record->billing_cycle->getLabel(), 'date' => $record->new_price_from->format(AppServiceProvider::dateFormat()), 'price' => Money::format((int) $record->new_price_cents, $record->currency)])
                        : $record->billing_cycle->getLabel())
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('next_due_date')
                    ->label('Next due')
                    ->date()
                    ->description(fn (Service $record) => self::dueSoon($record))
                    ->sortable()
                    ->color(fn (Service $record) => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Service $record) => $record->isOverdue() ? 'bold' : null),
                TextColumn::make('domainDetails.expires_at')
                    ->label('Expires')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (Service $record) => $record->domainDetails?->expires_at?->lessThan($record->next_due_date) ? 'danger' : null)
                    ->tooltip(__('Expiry at the registrar, from the last sync'))
                    ->visible(fn ($livewire) => ($tab ?? $livewire->activeTab ?? null) === 'domains'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->searchPlaceholder(__('Domain, username or client'))
            ->defaultSort('next_due_date')
            ->filters([
                SelectFilter::make('status')
                    ->options(ServiceStatus::class)
                    ->multiple()
                    ->default(array_map(fn (ServiceStatus $status) => $status->value, self::OPEN)),
                SelectFilter::make('type')
                    ->options(ServiceType::class),
                SelectFilter::make('server')
                    ->relationship('server', 'name'),
                SelectFilter::make('product_tags')
                    ->label('Product tags')
                    ->options(fn () => ProductTag::options())
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn (Builder $query, array $tags) => $query
                        ->whereHas('product.tags', fn (Builder $q) => $q->whereKey($tags)))),
                Filter::make('price_change')
                    ->label('Price change scheduled')
                    ->query(fn (Builder $query) => $query->whereNotNull('new_price_from')),
                Filter::make('overdue')
                    ->query(fn (Builder $query) => $query->billable()->whereDate('next_due_date', '<', today())),
                Filter::make('due_soon')
                    ->label(__('Due in the next :count days', ['count' => self::DUE_SOON_DAYS]))
                    ->query(fn (Builder $query) => $query->billable()->whereDate('next_due_date', '<=', today()->addDays(self::DUE_SOON_DAYS))),
            ])
            ->recordUrl(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    RecordPaymentAction::make(),
                    EditAction::make()
                        ->url(fn (Service $record) => ServiceResource::getUrl('edit', ['record' => $record])),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ChangePriceAction::bulk(),
                ]),
            ]);
    }

    /** The line under the service: a hosting account's username, else the label when the domain is the title. */
    private static function serviceDetail(Service $record): ?string
    {
        if ($record->type === ServiceType::Hosting && filled($record->username)) {
            return $record->username;
        }

        return filled($record->domain) && $record->label !== $record->domain ? $record->label : null;
    }

    /** How near a billable service's due date is, within DUE_SOON_DAYS or past it. */
    public static function dueSoon(Service $record): ?string
    {
        if (! in_array($record->status, ServiceStatus::billable(), true) || ! $record->next_due_date) {
            return null;
        }

        $days = (int) today()->diffInDays($record->next_due_date, false);

        return match (true) {
            $days > self::DUE_SOON_DAYS => null,
            $days > 1 => __('In :count days', ['count' => $days]),
            $days === 1 => __('Tomorrow'),
            $days === 0 => __('Today'),
            $days === -1 => __('1 day overdue'),
            default => __(':count days overdue', ['count' => -$days]),
        };
    }
}
