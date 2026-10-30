<?php

namespace App\Filament\Resources\Clients;

use App\Enums\AdminArea;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\GlobalSearch\ResultTitle;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\CreditRelationManager;
use App\Filament\Resources\Clients\RelationManagers\DomainsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\HostingRelationManager;
use App\Filament\Resources\Clients\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Clients\RelationManagers\OtherServicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\SignInsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\TaxInvoicesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Resources\Shared\ActivityLogsRelationManager;
use App\Models\Client;
use App\Models\ClientChangeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ClientResource extends Resource
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Main';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'last_name';

    protected static ?int $globalSearchSort = 1;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Clients;
    }

    /** Clients waiting for an answer to changes they asked for in the client area. */
    public static function getNavigationBadge(): ?string
    {
        $pending = ClientChangeRequest::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Change requests from the client area');
    }

    /** A client is also found by the domain of any of their services. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'company', 'email', 'vat_number', 'services.domain'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Client $record */
        return ResultTitle::withStatus("{$record->displayName()} #{$record->id}", $record->status);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Client $record */
        return array_filter([$record->email]);
    }

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            'hosting' => HostingRelationManager::class,
            'domains' => DomainsRelationManager::class,
            'other' => OtherServicesRelationManager::class,
            'orders' => OrdersRelationManager::class,
            'notices' => InvoicesRelationManager::class,
            'invoices' => TaxInvoicesRelationManager::class,
            'payments' => PaymentsRelationManager::class,
            'credit' => CreditRelationManager::class,
            'tickets' => TicketsRelationManager::class,
            'sign-ins' => SignInsRelationManager::class,
            'activity' => ActivityLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
