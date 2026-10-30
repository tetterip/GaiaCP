<?php

namespace App\Filament\Resources\TaxInvoices;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Filament\Resources\TaxInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\TaxInvoices\Schemas\TaxInvoiceInfolist;
use App\Filament\Resources\TaxInvoices\Tables\TaxInvoicesTable;
use App\Invoicing\IssueInvoice;
use App\Models\TaxInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Invoices (Settings → Invoices): issued from paid payment notices, read only. In the menu once invoices are on, or
 * while there are some.
 */
class TaxInvoiceResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = TaxInvoice::class;

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $slug = 'tax-invoices'; // /invoices is the payment notices'

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'number';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Billing;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && (IssueInvoice::enabled() || TaxInvoice::query()->exists());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaxInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxInvoices::route('/'),
            'view' => ViewTaxInvoice::route('/{record}'),
        ];
    }
}
