<?php

namespace App\Filament\Resources\Servers;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Servers\Pages\CompareServer;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Schemas\ServerForm;
use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Models\Server;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServerResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Server::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    // Settings → Services: in the Settings sub-navigation, not the sidebar.
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 35;

    protected static ?int $globalSearchSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function settingsDescription(): string
    {
        return __('cPanel/WHM, Plesk, HestiaCP and DirectAdmin servers that hosting accounts are created on, with their API logins.');
    }

    public static function form(Schema $schema): Schema
    {
        return ServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServers::route('/'),
            'create' => CreateServer::route('/create'),
            'edit' => EditServer::route('/{record}/edit'),
            'compare' => CompareServer::route('/{record}/compare'),
        ];
    }
}
