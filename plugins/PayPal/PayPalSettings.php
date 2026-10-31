<?php

namespace Plugins\PayPal;

use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** The PayPal app keys and mode. Super admins only: the secret can move money. */
class PayPalSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    public const DEVELOPER = 'https://developer.paypal.com/dashboard/applications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 23;

    protected static ?string $title = 'PayPal';

    protected static ?string $slug = 'paypal';

    public static function settingsDescription(): string
    {
        return __('Payments with PayPal: the keys of your PayPal app and the webhook that tells GaiaCP about payments.');
    }

    public static function pluginId(): string
    {
        return 'paypal';
    }

    public static function keys(): array
    {
        return (new PayPalPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkKeys')
                ->label('Check the keys')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn () => ! PayPalApi::configured())
                ->action(function (PayPalApi $paypal) {
                    try {
                        $paypal->check();
                    } catch (PayPalException $e) {
                        Notification::make()->danger()->title(__('PayPal refused the keys'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(PayPalApi::mode() === 'live' ? __('The live keys work') : __('The sandbox keys work'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $clientId = fn (string $mode) => TextInput::make("paypal.{$mode}_client_id")
            ->label('Client ID')
            ->maxLength(255);

        $secret = fn (string $mode) => TextInput::make("paypal.{$mode}_secret")
            ->label('Secret')
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder(fn () => filled(config("gaiacp.paypal.{$mode}_secret")) ? '••••••••' : null)
            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
            ->maxLength(255);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay with PayPal" on their unpaid payment notices in the client area once the client ID and secret of this mode are filled in. Pressing it opens PayPal\'s own page; when the client approves, GaiaCP takes the money only if the notice still asks for that amount, and records the payment once PayPal confirms it.'))
                    ->schema([
                        ToggleButtons::make('paypal.mode')
                            ->hiddenLabel()
                            ->options(['sandbox' => __('Sandbox'), 'live' => __('Live')])
                            ->colors(['sandbox' => 'warning', 'live' => 'success'])
                            ->helperText(__('Sandbox takes only PayPal\'s test accounts and no real money, but records the payments here like real ones: try it on a test client.'))
                            ->default('sandbox')
                            ->required()
                            ->inline(),
                    ]),
                Section::make(__('Webhook'))
                    ->description(new HtmlString(__(
                        'PayPal tells GaiaCP about payments through a webhook, for clients who close the page after approving and for payments that clear later. In <a href=":url" target="_blank" rel="noopener" class="underline">PayPal Developer → Apps & Credentials</a> open your app, press <strong>Add Webhook</strong> under Webhooks, give it the address below and tick these events. Do it for the sandbox app and for the live app.',
                        ['url' => self::DEVELOPER],
                    )))
                    ->schema([
                        TextInput::make('webhook_url')
                            ->label('Webhook URL')
                            ->formatStateUsing(fn () => PayPalApi::webhookUrl())
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                        Text::make(new HtmlString(__('Events: :events', ['events' => collect(PayPalController::EVENTS)->map(fn (string $e) => '<code>'.$e.'</code>')->join(', ')]))),
                    ]),
                Section::make(__('Sandbox app'))
                    ->description(new HtmlString(__('From <a href=":url" target="_blank" rel="noopener" class="underline">PayPal Developer → Apps & Credentials</a> with Sandbox selected: create an app (Merchant) and copy its keys.', ['url' => self::DEVELOPER])))
                    ->columns(2)
                    ->schema([$clientId('sandbox'), $secret('sandbox')]),
                Section::make(__('Live app'))
                    ->description(new HtmlString(__('From <a href=":url" target="_blank" rel="noopener" class="underline">PayPal Developer → Apps & Credentials</a> with Live selected. Live payments need a PayPal Business account.', ['url' => self::DEVELOPER])))
                    ->columns(2)
                    ->schema([$clientId('live'), $secret('live')]),
            ]);
    }
}
