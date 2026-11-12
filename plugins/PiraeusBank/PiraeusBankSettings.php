<?php

namespace Plugins\PiraeusBank;

use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The Piraeus Bank paycenter account and the addresses to give the bank. Super admins only: the password starts payments. */
class PiraeusBankSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 26;

    protected static ?string $title = 'Piraeus Bank';

    protected static ?string $slug = 'piraeusbank';

    public static function settingsDescription(): string
    {
        return __('Card and IRIS payments through Piraeus Bank: the paycenter account the bank gave you and the addresses to give the bank.');
    }

    public static function pluginId(): string
    {
        return 'piraeusbank';
    }

    public static function keys(): array
    {
        return (new PiraeusBankPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        $url = fn (string $name, string $label, string $result) => TextInput::make($name)
            ->label($label)
            ->formatStateUsing(fn () => PiraeusBankPayments::answerUrl($result))
            ->readOnly()
            ->copyable()
            ->dehydrated(false);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Addresses for the bank'))
                    ->description(__('Send these to Piraeus Bank when you ask for your test or live account: the bank sends clients back to them after paying, and answers with POST. The bank also asks for your server\'s outgoing IP address (your host can tell you). The addresses must not be behind a login, a maintenance page or a firewall challenge.'))
                    ->columns(2)
                    ->schema([
                        $url('website_url', 'Website URL', 'success')->formatStateUsing(fn () => url('/')),
                        $url('referrer_url', 'Referrer URL', 'success')->formatStateUsing(fn () => url('/')),
                        $url('success_url', __('Success page'), 'success'),
                        $url('failure_url', __('Failure page'), 'fail'),
                        $url('backlink_url', __('Backlink page'), 'cancel'),
                    ]),
                Section::make(__('Paycenter account'))
                    ->description(__('From the bank\'s set-up email. Clients see "Pay by card (Piraeus Bank)" on their unpaid payment notices once every field is filled in; GaiaCP records a payment only when the bank\'s answer is signed with that payment\'s ticket. The client\'s email, address, city, postcode and country must be filled in: the bank needs them for 3-D Secure.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('piraeusbank.merchant_id')->label('Merchant ID')->maxLength(50),
                        TextInput::make('piraeusbank.acquirer_id')->label('Acquirer ID')->helperText(__('14 for Piraeus Bank, unless the bank says otherwise.'))->maxLength(20),
                        TextInput::make('piraeusbank.pos_id')->label('POS ID')->maxLength(50),
                        TextInput::make('piraeusbank.username')->label('Username')->autocomplete('off')->maxLength(100),
                        TextInput::make('piraeusbank.password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.piraeusbank.password')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                    ]),
            ]);
    }
}
