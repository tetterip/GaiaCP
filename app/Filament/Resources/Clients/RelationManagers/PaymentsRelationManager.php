<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The payments recorded on the client's services, newest first; read-only like the Payments list. */
class PaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PaymentResource::canAccess();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return PaymentsTable::configure($table, showClient: false);
    }
}
