<?php

namespace App\Filament\Client\Resources\PaymentNotices;

use App\Enums\InvoiceStatus;
use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\PaymentNotices\Pages\ListPaymentNotices;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Models\Invoice;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client's payment notices (proformas, never "invoices"), cancelled ones left out. A notice shows every line,
 * a service hidden from the client area included: it is money they owe and they already got it by email.
 */
class PaymentNoticeResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = Invoice::class;

    protected static ?string $modelLabel = 'payment notice';

    protected static ?string $slug = 'payment-notices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string
    {
        return __('Billing');
    }

    protected static ?string $recordTitleAttribute = 'number';

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId())
            ->where('status', '!=', InvoiceStatus::Cancelled);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('date')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date(AppServiceProvider::dateFormat())
                    ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->state(fn (Invoice $record) => Money::format($record->total_cents, $record->currency)),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        InvoiceStatus::Unpaid->value => InvoiceStatus::Unpaid->getLabel(),
                        InvoiceStatus::Paid->value => InvoiceStatus::Paid->getLabel(),
                    ]),
                Filter::make('overdue')
                    ->query(fn (Builder $query) => $query->unpaid()->whereDate('due_date', '<', today())),
            ])
            // The chips over the table (ListPaymentNotices) set them and show which one is on
            ->filtersLayout(FiltersLayout::Hidden)
            ->hiddenFilterIndicators()
            ->recordActions([])
            ->emptyStateHeading(__('No payment notices yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.client.payment-notice')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentNotices::route('/'),
            'view' => ViewPaymentNotice::route('/{record}'),
        ];
    }
}
