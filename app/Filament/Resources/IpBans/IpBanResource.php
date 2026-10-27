<?php

namespace App\Filament\Resources\IpBans;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\IpBans\Pages\ListIpBans;
use App\Models\IpBan;
use App\Support\LoginSecurity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Settings → System → Banned IPs: the addresses LoginSecurity banned after failed logins and the ones an admin added,
 * where a ban is lifted. Super admins only.
 */
class IpBanResource extends Resource implements SettingsCard
{
    use LimitedToAdminArea;
    use TranslatesResourceLabels;

    protected static ?string $model = IpBan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 82;

    protected static ?string $navigationLabel = 'Banned IPs';

    protected static ?string $modelLabel = 'banned IP';

    protected static ?string $slug = 'banned-ips';

    protected static bool $isGloballySearchable = false;

    public static function settingsDescription(): string
    {
        return __('IP addresses kept out of the logins after failed attempts, or added by hand. Lift a ban here.');
    }

    public static function canCreate(): bool
    {
        return false; // "Ban an IP address" on the list
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip')->label('IP address')->weight('medium')->searchable()->copyable(),
                TextColumn::make('reason')->state(fn (IpBan $record) => $record->reasonText())->wrap(),
                TextColumn::make('expires_at')
                    ->label('Ban lifted')
                    ->dateTime(null)
                    ->placeholder(__('When lifted by hand'))
                    ->color(fn (IpBan $record) => $record->isActive() ? null : 'gray')
                    ->description(fn (IpBan $record) => $record->isActive() ? null : __('Ended'))
                    ->sortable(),
                TextColumn::make('created_at')->label('Banned')->dateTime(null)->sortable(),
                TextColumn::make('user.name')->label('Added by')->placeholder(__('Automatically'))->color('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('active')
                    ->label('Active bans only')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->active()),
            ])
            ->emptyStateHeading(__('No banned IP addresses'))
            ->recordActions([
                Action::make('lift')
                    ->label('Lift ban')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->requiresConfirmation()
                    ->modalHeading(fn (IpBan $record) => __('Lift the ban on :ip?', ['ip' => $record->ip]))
                    ->action(function (IpBan $record) {
                        LoginSecurity::lift($record);
                        Notification::make()->success()->title(__('Ban lifted'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIpBans::route('/'),
        ];
    }
}
