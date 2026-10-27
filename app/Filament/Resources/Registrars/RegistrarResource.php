<?php

namespace App\Filament\Resources\Registrars;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Registrars\Pages\EditRegistrar;
use App\Filament\Resources\Registrars\Pages\ListRegistrars;
use App\Filament\Resources\Registrars\Schemas\RegistrarForm;
use App\Filament\Resources\Registrars\Tables\RegistrarsTable;
use App\Models\Registrar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Registrars: in the Settings sub-navigation, not the sidebar. */
class RegistrarResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = Registrar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 34;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function settingsDescription(): string
    {
        return __('Registrar accounts and their logins, including Email Notifications for TLDs with no integrated registrar.');
    }

    public static function form(Schema $schema): Schema
    {
        return RegistrarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegistrarsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistrars::route('/'),
            'create' => CreateRegistrar::route('/create'),
            'edit' => EditRegistrar::route('/{record}/edit'),
        ];
    }
}
