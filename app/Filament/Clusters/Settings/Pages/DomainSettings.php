<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Enums\ServiceType;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Forms\NameserversInput;
use App\Models\Product;
use App\Support\DomainName;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Domain options like WHMCS's Domains settings; the registrar accounts themselves are under Registrars. */
class DomainSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static string|UnitEnum|null $navigationGroup = 'Products & domains';

    protected static ?int $navigationSort = 33;

    protected static ?string $title = 'Domains';

    protected static ?string $slug = 'domains';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::ServiceSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Nameservers, auto renew, free domains, IDN, late renewal fees and an SSL certificate offered with domains.');
    }

    public static function keys(): array
    {
        return ['domains.nameservers', 'domains.auto_renew', 'domains.renew_on_payment', 'domains.free_renew_requires_product', 'domains.idn', 'domains.late_fees', 'domains.ssl_product', 'domains.ssl_discount', 'domains.ssl_own_hosting'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description(__('Registrar accounts, their TLDs and prices are under Registrars.'))
                    ->schema([
                        NameserversInput::make('domains.nameservers')
                            ->label('Default nameservers')
                            ->helperText(__('Used when a new domain is registered without nameservers of its own. Two to four.')),
                    ]),
                Section::make(__('Renewals'))
                    ->schema([
                        Toggle::make('domains.auto_renew')
                            ->label('Auto renew new domains')
                            ->helperText(__('The default for new domains. A domain that does not auto renew gets no payment notices or renewal emails and ends at its due date. Each domain can change it under Automation.')),
                        Toggle::make('domains.renew_on_payment')
                            ->label('Renew at the registrar when a payment is recorded')
                            ->helperText(__('Unless the domain\'s expiry already covers the paid period. Off: record the payment, then renew at the registrar yourself.')),
                        Toggle::make('domains.free_renew_requires_product')
                            ->label('Free domains renew only with active hosting')
                            ->helperText(__('A free domain (price 0) is renewed by the daily run when its due date comes, as if paid. With this on, only while the client has active or suspended hosting for the same domain or from the same order; otherwise it is set to end at its due date.')),
                        Toggle::make('domains.late_fees')
                            ->label('Charge grace and redemption fees')
                            ->helperText(__('Once a domain has expired, its unpaid payment notice gets the TLD\'s grace fee, and the redemption fee on top after the grace period. Prices per TLD under Registrars.')),
                    ]),
                Section::make(__('SSL with domains'))
                    ->description(__('Offered when a client orders a domain on its own (domain search, Order page, public order page): a certificate for the same name, its own service in the same order and payment notice.'))
                    ->columns(2)
                    ->schema([
                        Select::make('domains.ssl_product')
                            ->label('SSL certificate')
                            ->options(fn () => Product::query()->clientOrderable()->where('type', ServiceType::Ssl)->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder(__('None'))
                            ->helperText(__('An SSL certificate product that clients can order. Its default billing cycle is used.'))
                            ->live(),
                        TextInput::make('domains.ssl_discount')
                            ->label('Discount on the first payment')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText(__('Off the certificate\'s first period when it is ordered with the domain; renewals are at its usual price.'))
                            ->visible(fn (Get $get) => filled($get('domains.ssl_product'))),
                        Toggle::make('domains.ssl_own_hosting')
                            ->label('Set up certificates on our own hosting')
                            ->helperText(__('When a certificate\'s domain is the client\'s hosting account on a cPanel or Plesk server here, GaiaCP validates it (a file on cPanel, a DNS record on Plesk) and installs the certificate once it is issued.'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Domain names'))
                    ->schema([
                        Toggle::make('domains.idn')
                            ->label('Allow IDN domains')
                            ->helperText(__('Domains in Greek or other non-Latin letters, e.g. παράδειγμα.gr. Shown as typed; registrars and cPanel get the punycode form (xn--...).')),
                    ]),
            ]);
    }

    protected function forStorage(array $values): array
    {
        $values['domains.nameservers'] = DomainName::nameservers($values['domains.nameservers'] ?? []);

        $values['domains.ssl_product'] = filled($values['domains.ssl_product'] ?? null) ? (int) $values['domains.ssl_product'] : null;
        $values['domains.ssl_discount'] = max(0, min(100, (int) ($values['domains.ssl_discount'] ?? 0)));

        foreach (['domains.auto_renew', 'domains.renew_on_payment', 'domains.free_renew_requires_product', 'domains.idn', 'domains.late_fees'] as $key) {
            $values[$key] = (bool) $values[$key];
        }

        return $values;
    }
}
