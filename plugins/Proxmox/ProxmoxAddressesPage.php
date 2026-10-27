<?php

namespace Plugins\Proxmox;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Settings → Proxmox → IP addresses: each Proxmox server's pool, and which VPS holds each address. */
class ProxmoxAddressesPage extends Page implements HasTable
{
    use InteractsWithTable;
    use LimitedToAdminArea;
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Proxmox IP addresses';

    protected static ?string $slug = 'proxmox/addresses';

    public function getBreadcrumbs(): array
    {
        return [
            ...SettingsCluster::unshiftClusterBreadcrumbs([]),
            ProxmoxSettings::getUrl() => __('Proxmox'),
            __('IP addresses'),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('A new VPS takes the first free address of its server, and gives it back when it is terminated. A server with no addresses here gives its VPS an address by DHCP.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add addresses')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    Select::make('server_id')
                        ->label('Server')
                        ->options(fn () => self::servers())
                        ->required(),
                    Textarea::make('addresses')
                        ->label('Addresses')
                        ->rows(6)
                        ->required()
                        ->placeholder("203.0.113.10/24 203.0.113.1\n203.0.113.20-203.0.113.40/24 203.0.113.1")
                        ->helperText(__('One per line: the address with its prefix, then the gateway. A range of IPv4 addresses takes a dash.')),
                ])
                ->action(function (array $data) {
                    [$addresses, $invalid] = ProxmoxAddress::parse((string) $data['addresses']);
                    $added = 0;

                    foreach ($addresses as $address) {
                        $added += ProxmoxAddress::query()->firstOrCreate(
                            ['server_id' => $data['server_id'], 'address' => $address['address']],
                            ['prefix' => $address['prefix'], 'gateway' => $address['gateway']],
                        )->wasRecentlyCreated ? 1 : 0;
                    }

                    $notification = Notification::make()->title(__(':count address(es) added', ['count' => $added]));

                    $invalid === []
                        ? $notification->success()->send()
                        : $notification->warning()->persistent()->body(__('Not understood: :lines', ['lines' => implode(', ', $invalid)]))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ProxmoxAddress::query()->with(['server', 'service']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('address')
                    ->label('Address')
                    ->searchable()
                    ->formatStateUsing(fn (ProxmoxAddress $record) => "{$record->address}/{$record->prefix}")
                    ->description(fn (ProxmoxAddress $record) => $record->gateway ? __('Gateway :gateway', ['gateway' => $record->gateway]) : null),
                TextColumn::make('server.name')
                    ->label('Server'),
                TextColumn::make('service.domain')
                    ->label('VPS')
                    ->placeholder(__('Free'))
                    ->url(fn (ProxmoxAddress $record) => $record->service_id ? route('filament.admin.resources.services.edit', $record->service_id) : null),
            ])
            ->filters([
                SelectFilter::make('server_id')
                    ->label('Server')
                    ->options(fn () => self::servers()),
                TernaryFilter::make('service_id')
                    ->label('In use')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('release')
                    ->label('Free it')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (ProxmoxAddress $record) => $record->service_id !== null)
                    ->requiresConfirmation()
                    ->modalDescription(__('Only when the VPS no longer uses it: GaiaCP changes nothing on Proxmox.'))
                    ->action(fn (ProxmoxAddress $record) => $record->update(['service_id' => null])),
                DeleteAction::make()
                    ->visible(fn (ProxmoxAddress $record) => $record->service_id === null),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->action(fn ($records) => $records->whereNull('service_id')->each->delete()),
            ])
            ->emptyStateHeading(__('No IP addresses yet'))
            ->emptyStateDescription(__('Add the addresses each Proxmox server may give its VPS, or leave it empty for DHCP.'));
    }

    /** @return array<int, string> */
    private static function servers(): array
    {
        return Server::query()->where('type', 'proxmox')->orderBy('name')->pluck('name', 'id')->all();
    }
}
