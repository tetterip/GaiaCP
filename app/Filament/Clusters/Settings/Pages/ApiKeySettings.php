<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCard;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Pages\ApiDocumentation;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\IpUtils;
use UnitEnum;

/**
 * Settings → API keys: the keys of the REST API (/api/v1), each with its permissions per area and the IPs it may
 * call from. Super admins only: a key reads and changes clients and payments. A key is shown once, when it is made;
 * only its SHA-256 is kept. A revoked key stays listed (the activity log names it) and never works again.
 */
class ApiKeySettings extends Page implements HasTable, SettingsCard
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'API keys';

    protected static ?string $slug = 'api-keys';

    public static function settingsDescription(): string
    {
        return __('Keys for the REST API, so your own scripts and sites can read and change clients, orders and payments.');
    }

    public function getSubheading(): ?string
    {
        return __('Calls go to :url with the key as a bearer token, and do what the panel does: they are in the activity log with the key\'s name and send the same emails and webhooks.', ['url' => url('api/v1')]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('docs')
                ->label('Documentation')
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('gray')
                ->url(fn () => ApiDocumentation::getUrl()),
            Action::make('create')
                ->label('New API key')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('New API key'))
                ->schema(self::formFields())
                ->action(function (array $data) {
                    [$key, $token] = ApiKey::generate(self::fromForm($data));
                    ActivityLog::record('api_key.created', "API key {$key->name} created", properties: ['api_key_id' => $key->id, 'permissions' => $key->permissions]);
                    self::showToken($token);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ApiKey::query())
            ->defaultSort(fn ($query) => $query->orderByRaw('revoked_at is not null')->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ApiKey $record) => $record->token_prefix.'…'),
                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(fn (ApiKey $record) => self::permissionsLabel($record))
                    ->listWithLineBreaks(),
                TextColumn::make('allowed_ips')
                    ->label('Allowed IPs')
                    ->state(fn (ApiKey $record) => $record->allowed_ips ? implode(', ', $record->allowed_ips) : __('Any'))
                    ->wrap(),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->state(fn (ApiKey $record) => $record->last_used_at?->diffForHumans())
                    ->description(fn (ApiKey $record) => $record->last_used_ip)
                    ->placeholder(__('Never')),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ApiKey $record) => $record->revoked() ? __('Revoked') : __('Active'))
                    ->color(fn (ApiKey $record) => $record->revoked() ? 'gray' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('revoked')
                    ->label('Revoked')
                    ->nullable()
                    ->attribute('revoked_at'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->hidden(fn (ApiKey $record) => $record->revoked())
                    ->modalHeading(fn (ApiKey $record) => $record->name)
                    ->schema(self::formFields())
                    ->fillForm(fn (ApiKey $record) => [
                        'name' => $record->name,
                        'allowed_ips' => implode("\n", $record->allowed_ips ?? []),
                        'permissions' => $record->permissionsByArea(),
                    ])
                    ->action(function (ApiKey $record, array $data) {
                        $record->update(self::fromForm($data));
                        ActivityLog::record('api_key.updated', "API key {$record->name} changed", properties: ['api_key_id' => $record->id, 'permissions' => $record->permissions]);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->hidden(fn (ApiKey $record) => $record->revoked())
                    ->requiresConfirmation()
                    ->modalDescription(__('Calls with this key stop working at once, and it can\'t be turned back on. Make a new key for whatever still uses it.'))
                    ->action(function (ApiKey $record) {
                        $record->update(['revoked_at' => now()]);
                        ActivityLog::record('api_key.revoked', "API key {$record->name} revoked", properties: ['api_key_id' => $record->id]);
                        Notification::make()->success()->title(__('Revoked'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No API keys yet'))
            ->emptyStateDescription(__('Make a key for each script or site that uses the API, with only the permissions it needs.'));
    }

    /** @return list<mixed> */
    private static function formFields(): array
    {
        $areas = collect(ApiKey::PERMISSIONS)->map(fn (array $actions, string $area) => CheckboxList::make("permissions.{$area}")
            ->label(ApiKey::areaOptions()[$area])
            ->options(array_intersect_key(ApiKey::actionOptions(), array_flip($actions)))
            ->columns(4)
            ->gridDirection('row'))->values()->all();

        return [
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->placeholder(__('Website order form'))
                ->helperText(__('What uses it, so you know which key to revoke.')),
            Fieldset::make(__('Permissions'))
                ->columns(1)
                ->schema([
                    Actions::make([
                        Action::make('permissions_all')
                            ->label(__('Select all'))
                            ->link()
                            ->action(fn (Set $set) => self::setPermissions($set, fn (array $actions) => $actions)),
                        Action::make('permissions_read')
                            ->label(__('Read only'))
                            ->link()
                            ->action(fn (Set $set) => self::setPermissions($set, fn (array $actions) => array_values(array_intersect($actions, ['read'])))),
                        Action::make('permissions_none')
                            ->label(__('Clear all'))
                            ->link()
                            ->color('gray')
                            ->action(fn (Set $set) => self::setPermissions($set, fn () => [])),
                    ])->key('permissionActions'),
                    ...$areas,
                ]),
            Textarea::make('allowed_ips')
                ->label('Allowed IPs')
                ->rows(3)
                ->placeholder("203.0.113.10\n2001:db8::/32")
                ->helperText(__('One IP address or range (CIDR) per line. Empty = from anywhere.'))
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    foreach (self::ipLines($value) as $line) {
                        if (! self::validIp($line)) {
                            $fail(__(':value is not an IP address or range.', ['value' => $line]));

                            return;
                        }
                    }
                }),
        ];
    }

    /** Ticks, in every area, the actions $pick keeps of that area's actions. */
    private static function setPermissions(Set $set, Closure $pick): void
    {
        foreach (ApiKey::PERMISSIONS as $area => $actions) {
            $set("permissions.{$area}", $pick($actions));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromForm(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'permissions' => ApiKey::permissionsFrom((array) ($data['permissions'] ?? [])),
            'allowed_ips' => self::ipLines($data['allowed_ips'] ?? null) ?: null,
        ];
    }

    /** @return list<string> */
    private static function ipLines(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: []))));
    }

    private static function validIp(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits <= $max && IpUtils::checkIp($ip, $value));
    }

    /** @return list<string> "Clients: read, update", one line per area, or "None" */
    private static function permissionsLabel(ApiKey $key): array
    {
        $actions = ApiKey::actionOptions();

        $parts = collect($key->permissionsByArea())
            ->filter()
            ->map(fn (array $list, string $area) => ApiKey::areaOptions()[$area].': '.collect($list)->map(fn (string $action) => mb_strtolower($actions[$action]))->join(', '));

        return $parts->isEmpty() ? [__('None')] : $parts->values()->all();
    }

    private static function showToken(string $token): void
    {
        Notification::make()
            ->success()
            ->persistent()
            ->title(__('Copy the API key now'))
            ->body(__('It is shown only this once: :key', ['key' => $token]))
            ->send();
    }
}
