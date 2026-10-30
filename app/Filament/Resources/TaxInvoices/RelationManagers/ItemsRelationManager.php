<?php

namespace App\Filament\Resources\TaxInvoices\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\TaxInvoiceItem;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The invoice's lines, as copied from its payment notice. */
class ItemsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'items';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $money = fn (string $field) => fn (TaxInvoiceItem $record) => Money::format($record->{$field}, $this->getOwnerRecord()->currency);

        return $table
            ->columns([
                TextColumn::make('description')
                    ->wrap(),
                TextColumn::make('period')
                    ->state(fn (TaxInvoiceItem $record) => $record->period_start && $record->period_end
                        ? $record->period_start->format(AppServiceProvider::dateFormat()).' - '.$record->period_end->format(AppServiceProvider::dateFormat())
                        : null)
                    ->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing($money('amount_cents')),
                TextColumn::make('tax_cents')
                    ->label('Tax')
                    ->formatStateUsing($money('tax_cents')),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing($money('total_cents')),
            ])
            ->recordUrl(fn (TaxInvoiceItem $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null)
            ->paginated(false);
    }
}
