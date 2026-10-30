<?php

namespace App\Filament\Client\Resources\Invoices;

use App\Filament\Client\Concerns\ScopedToClient;
use App\Filament\Client\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Invoicing\IssueInvoice;
use App\Models\TaxInvoice;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client's invoices (Settings → Invoices), each a PDF to download. In the menu while invoices are on, or once the
 * client has one.
 */
class InvoiceResource extends Resource
{
    use ScopedToClient;

    protected static ?string $model = TaxInvoice::class;

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $slug = 'invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): string
    {
        return __('Billing');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return IssueInvoice::enabled() || static::getEloquentQuery()->exists();
    }

    protected static function scopeToClient(Builder $query): Builder
    {
        return $query->where('client_id', static::clientId());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('notice'))
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TaxInvoice $record) => $record->isCreditNote() ? __('Credit note') : null),
                TextColumn::make('date')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
                TextColumn::make('notice.number')
                    ->label('Payment notice'),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->state(fn (TaxInvoice $record) => Money::format($record->signedTotalCents(), $record->currency)),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->recordActions([ViewTaxInvoice::downloadAction()->iconButton()->tooltip(__('Download PDF'))])
            ->recordAction('downloadPdf')
            ->emptyStateHeading(__('No invoices yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }
}
