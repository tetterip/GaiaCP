<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The order's items. Each is a service: open it to edit it or to act on it alone. */
class ItemsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'services';

    protected static ?string $title = 'Items';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $table = ServicesTable::configure($table, showClient: false)
            ->filters([])
            ->defaultSort('id');

        return $table->pushColumns([
            TextColumn::make('first_payment')
                ->label('First payment')
                ->state(fn (Service $record) => Money::format($record->firstPaymentCents(), $record->currency))
                ->description(fn (Service $record) => $record->payments()->exists() ? __('Paid') : null),
        ]);
    }
}
