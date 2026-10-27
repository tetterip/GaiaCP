<?php

namespace App\Filament\Resources\Users;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Settings → System → Admin users: the people who log in to this panel. Only admin users are listed here. */
class UserResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'admin user';

    protected static ?string $slug = 'admin-users';

    protected static ?string $navigationLabel = 'Admin users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function settingsDescription(): string
    {
        return __('Who can log in to this panel: add admins, change their password or language, deactivate them, reset two-factor authentication.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->admins();
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
