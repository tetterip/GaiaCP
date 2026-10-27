<?php

namespace Plugins\Stripe;

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

/** The Stripe keys and mode for card payments. Super admins only: the secret key can move money. */
class StripeSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    public const DASHBOARD = 'https://dashboard.stripe.com';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 22;

    protected static ?string $title = 'Stripe';

    protected static ?string $slug = 'stripe';

    public static function settingsDescription(): string
    {
        return __('Card payments: the keys of your Stripe account and the webhook that confirms payments.');
    }

    public static function pluginId(): string
    {
        return 'stripe';
    }

    public static function keys(): array
    {
        return (new StripePlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkKey')
                ->label('Check the key')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn () => ! StripeApi::configured())
                ->action(function (StripeApi $stripe) {
                    try {
                        $stripe->check();
                    } catch (StripeException $e) {
                        Notification::make()->danger()->title(__('Stripe refused the key'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(StripeApi::mode() === 'live' ? __('The live key works') : __('The test key works'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $secret = fn (string $key, string $label, string $placeholder, array $prefixes) => TextInput::make("stripe.{$key}")
            ->label($label)
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder(fn () => filled(config("gaiacp.stripe.{$key}")) ? '••••••••' : $placeholder)
            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
            ->startsWith($prefixes)
            ->maxLength(255);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay by card" on their unpaid payment notices in the client area once the secret key of this mode is filled in. Pressing it opens Stripe\'s own payment page, so no card details reach GaiaCP; the payment is recorded on the notice when Stripe confirms it.'))
                    ->schema([
                        ToggleButtons::make('stripe.mode')
                            ->hiddenLabel()
                            ->options(['test' => __('Test'), 'live' => __('Live')])
                            ->colors(['test' => 'warning', 'live' => 'success'])
                            ->helperText(__('Test takes only Stripe\'s test cards (e.g. 4242 4242 4242 4242) and no real money, but records the payments here like real ones: try it on a test client.'))
                            ->default('test')
                            ->required()
                            ->inline(),
                    ]),
                Section::make(__('Webhook'))
                    ->description(new HtmlString(__(
                        'Stripe tells GaiaCP about payments through a webhook. In the <a href=":url" target="_blank" rel="noopener" class="underline">Stripe dashboard → Developers → Webhooks</a> press <strong>Add destination</strong>, choose these events, and give it the address below. Do it once in test mode and once in live mode; each gives its own signing secret (whsec_…).',
                        ['url' => self::DASHBOARD.'/webhooks'],
                    )))
                    ->schema([
                        TextInput::make('webhook_url')
                            ->label('Endpoint URL')
                            ->formatStateUsing(fn () => StripeApi::webhookUrl())
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                        Text::make(new HtmlString(__('Events: :events', ['events' => collect(StripeController::EVENTS)->map(fn (string $e) => '<code>'.$e.'</code>')->join(', ')]))),
                    ]),
                Section::make(__('Test keys'))
                    ->description(new HtmlString(__('From the <a href=":url" target="_blank" rel="noopener" class="underline">Stripe dashboard → Developers → API keys</a> with Test mode on.', ['url' => self::DASHBOARD.'/test/apikeys'])))
                    ->columns(2)
                    ->schema([
                        $secret('test_secret_key', __('Secret key'), 'sk_test_…', ['sk_test_', 'rk_test_']),
                        $secret('test_webhook_secret', __('Webhook signing secret'), 'whsec_…', ['whsec_']),
                    ]),
                Section::make(__('Live keys'))
                    ->description(new HtmlString(__('From the <a href=":url" target="_blank" rel="noopener" class="underline">Stripe dashboard → Developers → API keys</a>, live mode.', ['url' => self::DASHBOARD.'/apikeys'])))
                    ->columns(2)
                    ->schema([
                        $secret('live_secret_key', __('Secret key'), 'sk_live_…', ['sk_live_', 'rk_live_']),
                        $secret('live_webhook_secret', __('Webhook signing secret'), 'whsec_…', ['whsec_']),
                    ]),
            ]);
    }
}
