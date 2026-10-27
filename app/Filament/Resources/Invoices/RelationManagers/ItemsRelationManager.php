<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\ServiceResource;
use App\Invoices\GenerateInvoices;
use App\Models\InvoiceItem;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The notice's lines: one service's cycle each, paid when that cycle's payment is recorded. */
class ItemsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service'))
            ->columns([
                // Domain, hosting, maintenance or other, as on the order's items; an "Add funds" line has no service.
                TextColumn::make('type')
                    ->state(fn (InvoiceItem $record) => $record->service?->type)
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('description'),
                TextColumn::make('period')
                    ->state(fn (InvoiceItem $record) => GenerateInvoices::period($record)),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (InvoiceItem $record) => Money::format($record->amount_cents, $this->getOwnerRecord()->currency)),
                TextColumn::make('tax_cents')
                    ->label('Tax')
                    ->formatStateUsing(fn (InvoiceItem $record) => Money::format($record->tax_cents, $this->getOwnerRecord()->currency)),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (InvoiceItem $record) => Money::format($record->total_cents, $this->getOwnerRecord()->currency)),
                IconColumn::make('paid')
                    ->state(fn (InvoiceItem $record) => $record->payment_id !== null)
                    ->boolean(),
            ])
            ->recordUrl(fn (InvoiceItem $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null)
            ->paginated(false);
    }
}
