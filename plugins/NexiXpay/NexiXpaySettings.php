<?php

namespace Plugins\NexiXpay;

use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The Nexi XPay API keys and mode. Super admins only: the key can see and move payments. */
class NexiXpaySettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 27;

    protected static ?string $title = 'Nexi XPay';

    protected static ?string $slug = 'nexi-xpay';

    public static function settingsDescription(): string
    {
        return __('Card payments through Nexi XPay: the API key from Nexi\'s back office.');
    }

    public static function pluginId(): string
    {
        return 'nexi-xpay';
    }

    public static function keys(): array
    {
        return (new NexiXpayPlugin)->settingKeys();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkKey')
                ->label('Check the key')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn () => ! NexiXpayApi::configured())
                ->action(function (NexiXpayApi $nexi) {
                    try {
                        $nexi->check();
                    } catch (NexiXpayException $e) {
                        Notification::make()->danger()->title(__('Nexi refused the key'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(NexiXpayApi::mode() === 'live' ? __('The live key works') : __('The test key works'))->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $key = fn (string $mode) => TextInput::make("nexi_xpay.{$mode}_api_key")
            ->label($mode === 'live' ? __('Live API key') : __('Test API key'))
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder(fn () => filled(config("gaiacp.nexi_xpay.{$mode}_api_key")) ? '••••••••' : null)
            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
            ->maxLength(255);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay by card (Nexi)" on their unpaid payment notices in the client area once the API key of this mode is filled in. Pressing it opens Nexi\'s own page; GaiaCP records the payment only after reading it back from Nexi with this key, and only if Nexi took the amount asked for. Every hour it asks Nexi about the payments still waiting, for clients who close the page.'))
                    ->schema([
                        ToggleButtons::make('nexi_xpay.mode')
                            ->hiddenLabel()
                            ->options(['test' => __('Test'), 'live' => __('Live')])
                            ->colors(['test' => 'warning', 'live' => 'success'])
                            ->helperText(__('Test uses Nexi\'s sandbox and no real money, but records the payments here like real ones: try it on a test client.'))
                            ->default('test')
                            ->required()
                            ->inline(),
                    ]),
                Section::make(__('API keys'))
                    ->description(__('Create an API key in Nexi\'s back office (its integrations section); a sandbox account from Nexi\'s developer portal gives the test one. Nexi sends the result of each payment to GaiaCP by itself, so nothing else needs setting up there. Payments should be captured at once (immediate accounting), so that an authorised payment is a paid one.'))
                    ->columns(2)
                    ->schema([$key('test'), $key('live')]),
            ]);
    }
}
