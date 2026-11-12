<?php

namespace Plugins\MastercardGateway;

use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The Mastercard Gateway address, merchant IDs and API passwords. Super admins only: the password can move money. */
class MastercardSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 28;

    protected static ?string $title = 'Mastercard Gateway';

    protected static ?string $slug = 'mastercard';

    public static function settingsDescription(): string
    {
        return __('Card payments through Mastercard Gateway (MPGS): the gateway address, merchant ID and API password your bank gave you.');
    }

    public static function pluginId(): string
    {
        return 'mastercard';
    }

    public static function keys(): array
    {
        return (new MastercardGatewayPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkDetails')
                ->label('Check the details')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn () => ! MastercardApi::configured())
                ->action(function (MastercardApi $gateway) {
                    try {
                        $gateway->check();
                    } catch (MastercardException $e) {
                        Notification::make()->danger()->title(__('The gateway refused the details'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(MastercardApi::mode() === 'live' ? __('The live details work') : __('The test details work'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $merchantId = fn (string $mode) => TextInput::make("mastercard.{$mode}_merchant_id")
            ->label('Merchant ID')
            ->helperText($mode === 'test' ? __('Test merchant IDs start with TEST.') : null)
            ->maxLength(40);

        $password = fn (string $mode) => TextInput::make("mastercard.{$mode}_api_password")
            ->label('API password')
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder(fn () => filled(config("gaiacp.mastercard.{$mode}_api_password")) ? '••••••••' : null)
            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
            ->maxLength(255);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay by card" with the name below on their unpaid payment notices in the client area once the merchant ID and API password of this mode are filled in. Pressing it opens the gateway\'s own payment page; GaiaCP records the payment only after reading the order back from the gateway as the merchant, and only if it captured the amount asked for. Every hour it asks the gateway about the payments still waiting, for clients who close the page.'))
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('mastercard.mode')
                            ->hiddenLabel()
                            ->options(['test' => __('Test'), 'live' => __('Live')])
                            ->colors(['test' => 'warning', 'live' => 'success'])
                            ->helperText(__('Test uses the test merchant ID and no real money, but records the payments here like real ones: try it on a test client.'))
                            ->default('test')
                            ->required()
                            ->inline(),
                        TextInput::make('mastercard.display_name')
                            ->label('Name shown to clients')
                            ->placeholder('Mastercard')
                            ->helperText(__('On the button, "Pay by card (name)": your bank\'s name, say.'))
                            ->maxLength(40),
                    ]),
                Section::make(__('Gateway'))
                    ->description(__('The gateway your bank\'s account is on. Your bank\'s set-up email or its integration guide names it; a bank with its own address (for example its own domain) is Custom.'))
                    ->columns(2)
                    ->schema([
                        Select::make('mastercard.host')
                            ->label('Gateway')
                            ->options([
                                'eu' => __('Europe (:host)', ['host' => MastercardApi::HOSTS['eu']]),
                                'ap' => __('Asia Pacific (:host)', ['host' => MastercardApi::HOSTS['ap']]),
                                'na' => __('North America (:host)', ['host' => MastercardApi::HOSTS['na']]),
                                'custom' => __('Custom'),
                            ])
                            ->default('eu')
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextInput::make('mastercard.custom_host')
                            ->label('Gateway address')
                            ->placeholder('ap-gateway.mastercard.com')
                            ->helperText(__('Only the host name, without https:// or a path.'))
                            ->visible(fn (Get $get) => $get('mastercard.host') === 'custom')
                            ->required(fn (Get $get) => $get('mastercard.host') === 'custom')
                            ->maxLength(255),
                    ]),
                Section::make(__('Test merchant'))
                    ->description(__('From the bank: the test merchant ID and, in the gateway\'s Merchant Administration under Admin → Integration Settings, the API password.'))
                    ->columns(2)
                    ->schema([$merchantId('test'), $password('test')]),
                Section::make(__('Live merchant'))
                    ->description(__('The same for the live merchant ID, once the bank switches the account on.'))
                    ->columns(2)
                    ->schema([$merchantId('live'), $password('live')]),
            ]);
    }
}
