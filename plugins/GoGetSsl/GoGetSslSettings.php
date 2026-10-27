<?php

namespace Plugins\GoGetSsl;

use App\Actions\CertificateLifecycle;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Provisioning\ProvisioningException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** GoGetSSL's API login. Super admins only, like Servers and Registrars. Each SSL product picks its certificate. */
class GoGetSslSettings extends SettingsFormPage
{
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 74;

    protected static ?string $title = 'GoGetSSL';

    protected static ?string $slug = 'gogetssl';

    public static function settingsDescription(): string
    {
        return __('Sells SSL certificates (Sectigo, DigiCert, GeoTrust, RapidSSL) through a GoGetSSL reseller account.');
    }

    public static function pluginId(): string
    {
        return 'gogetssl';
    }

    public static function keys(): array
    {
        return (new GoGetSslPlugin)->settingKeys();
    }

    protected function afterSaving(array $values, array $previous): void
    {
        app(CertificateLifecycle::class)->forgetProducts(new GoGetSslPlugin); // the product form's list is read again
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function () {
                    // The login on the form, saved or not (a blank password = the saved one).
                    $state = $this->form->getRawState();
                    $client = new GoGetSslClient(
                        (string) (data_get($state, 'gogetssl.username') ?: config('gaiacp.gogetssl.username')),
                        (string) (data_get($state, 'gogetssl.password') ?: config('gaiacp.gogetssl.password')),
                    );

                    try {
                        $balance = $client->balance();
                        $products = count($client->products());
                    } catch (ProvisioningException $e) {
                        Notification::make()->danger()->title(__('Connection failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('Connected: :count certificate(s) for sale', ['count' => $products]))
                        ->body(__('Account balance: :balance', ['balance' => trim($balance['balance'].' '.$balance['currency'])]))
                        ->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Connection'))
                    ->description(new HtmlString(__('In GoGetSSL open <strong>Reseller modules</strong> (or API settings) and create the API password; the username is your GoGetSSL login. Orders are paid from your GoGetSSL balance, so keep it topped up. Then make a product of type SSL certificate and pick its certificate.')))
                    ->columns(2)
                    ->schema([
                        TextInput::make('gogetssl.username')
                            ->label('API username')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('gogetssl.password')
                            ->label('API password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.gogetssl.password')) ? '••••••••' : '')
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                        Select::make('gogetssl.dcv_method')
                            ->label('Validation of a new certificate')
                            ->options(collect(CertificateLifecycle::METHODS)->mapWithKeys(fn (string $method) => [$method => CertificateLifecycle::methodLabel($method)])->all())
                            ->selectablePlaceholder(false)
                            ->helperText(__('How the client proves the domain is theirs, unless they pick another way in the client area.')),
                    ]),
            ]);
    }
}
