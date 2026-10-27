<?php

namespace Plugins\Proxmox;

use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** How a new VPS is set up on Proxmox, and what its client may do. The servers themselves are under Settings → Servers. */
class ProxmoxSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 77;

    protected static ?string $title = 'Proxmox';

    protected static ?string $slug = 'proxmox';

    public static function settingsDescription(): string
    {
        return __('Sells VPS on Proxmox VE: creates each one from a template, gives it an IP address, suspends, starts, stops and removes it.');
    }

    public static function pluginId(): string
    {
        return 'proxmox';
    }

    public static function keys(): array
    {
        return (new ProxmoxPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addresses')
                ->label('IP addresses')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->url(fn () => ProxmoxAddressesPage::getUrl()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('New VPS'))
                    ->description(new HtmlString(__('Add the Proxmox server under Settings → Servers with the type <strong>Proxmox VE</strong>. Each product\'s package is the name of a KVM template with cloud-init: the VPS is a full clone of it, with its CPU, memory and disk, on the template\'s node.')))
                    ->columns(2)
                    ->schema([
                        TextInput::make('proxmox.ci_user')
                            ->label('Login user inside the VPS')
                            ->required()
                            ->maxLength(32)
                            ->regex('/^[a-z_][a-z0-9_-]*$/')
                            ->helperText(__('cloud-init gives this user the service\'s password. root on most images.')),
                        TextInput::make('proxmox.storage')
                            ->label('Storage for new VPS')
                            ->maxLength(100)
                            ->placeholder('local-lvm')
                            ->helperText(__('Empty = the template\'s own storage.')),
                        TextInput::make('proxmox.nameserver')
                            ->label('DNS servers')
                            ->maxLength(255)
                            ->placeholder('1.1.1.1 9.9.9.9')
                            ->helperText(__('Empty = those of the Proxmox node.')),
                    ]),
                Section::make(__('Clients'))
                    ->description(__('Each client gets a Proxmox user (their service\'s username, realm "Proxmox VE authentication server") with the service\'s password, which sees only their VPS: Log in on their service page opens the Proxmox web interface, where they find its console.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('proxmox.client_role')
                            ->label('Proxmox role of the client')
                            ->required()
                            ->maxLength(100)
                            ->helperText(__('PVEVMUser lets them use the console, start and stop it, and make backups. Make a role of your own in Proxmox for less.')),
                        Toggle::make('proxmox.client_power')
                            ->label('Start, Shut down and Restart on the client\'s service page'),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['proxmox.client_power'] = (bool) $values['proxmox.client_power'];

        return $values;
    }
}
