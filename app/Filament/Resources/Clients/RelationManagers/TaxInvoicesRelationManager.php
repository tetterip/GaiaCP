<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\TaxInvoices\Tables\TaxInvoicesTable;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Invoicing\IssueInvoice;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The client's invoices (Settings → Invoices), newest first, each with its PDF; shown while invoices are on or once the client has one. */
class TaxInvoicesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'taxInvoices';

    protected static ?string $title = 'Invoices';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentCheck;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Client $ownerRecord */
        return TaxInvoiceResource::canAccess() && (IssueInvoice::enabled() || $ownerRecord->taxInvoices()->exists());
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return TaxInvoicesTable::configure($table, showClient: false);
    }
}
