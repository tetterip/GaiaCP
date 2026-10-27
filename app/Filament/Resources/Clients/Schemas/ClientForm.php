<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientStatus;
use App\Enums\MailTemplate;
use App\Filament\Forms\PasswordInput;
use App\Models\Client;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use App\Support\ClientFields;
use App\Support\Currencies;
use App\Support\Locales;
use App\Support\MailTemplates;
use App\Support\Region;
use App\Support\TermsOfService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Intl\Countries;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Contact'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('company')
                            ->label('Company name')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('Reminders go here; also the registrant email for domains.')),
                        Select::make('locale')
                            ->label('Email language')
                            ->options(Locales::available())
                            ->placeholder(fn () => __('Default (:language)', ['language' => Locales::available()[Locales::default()]]))
                            ->helperText(__('The language of the emails this client gets.'))
                            ->visible(Locales::multiple()),
                        Toggle::make('send_signup_email')
                            ->label('Send the Client Signup Email')
                            ->helperText(__('Off for clients you are moving from another system.'))
                            ->default(fn () => MailTemplates::enabled(MailTemplate::ClientSignup))
                            ->dehydrated(false)
                            ->visibleOn('create')
                            ->hidden(fn (Get $get) => filled($get('client_area_password'))),
                        TextInput::make('phone')
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(50)
                            ->helperText(__('With country code, e.g. +30 6912345678 (registries require it).')),
                    ]),

                Section::make(__('Client area'))
                    ->description(__('Fill in a password to give the client a login to the client area now, with their email address as the username. Leave it blank to invite them later from the Client area menu.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visibleOn('create')
                    ->schema([
                        PasswordInput::make('client_area_password')
                            ->label('Client area password')
                            ->rule(Password::min(10)->letters()->numbers())
                            ->helperText(__('If an admin user logs in with this email, they are linked to the client instead and keep their own password.'))
                            ->dehydrated(false)
                            ->live(onBlur: true),
                        Toggle::make('send_login_email')
                            ->label('Email the login details')
                            ->helperText(__('Sends the Client Area Login Details email with the password, instead of the Client Signup Email. Its switch under Settings → Email templates sets whether this starts ticked.'))
                            ->default(fn () => MailTemplates::enabled(MailTemplate::ClientAreaLogin))
                            ->dehydrated(false)
                            ->visible(fn (Get $get) => filled($get('client_area_password'))),
                    ]),

                Section::make(__('Tax details'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('vat_number')
                            ->label('VAT number')
                            ->maxLength(50)
                            // e.g. the ΑΑΔΕ plugin's lookup
                            ->suffixActions(app(PluginManager::class)->clientVatNumberActions()),
                        TextInput::make('tax_office')
                            ->label('Tax office')
                            ->maxLength(255),
                        TextInput::make('profession')
                            ->label('Profession')
                            ->maxLength(255),
                        Toggle::make('tax_exempt')
                            ->label('Tax exempt')
                            ->helperText(__('No tax on this client\'s payment notices (Settings → Tax).')),
                    ]),

                Section::make(__('More details'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn () => ClientFields::all()->isNotEmpty())
                    ->schema(fn () => ClientFields::components(ClientFields::all())),

                Section::make(__('Address'))
                    ->description(__('Used as the registrant contact for domain registrations.'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('address1')
                            ->label('Address 1')
                            ->maxLength(255),
                        TextInput::make('address2')
                            ->label('Address 2')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(255),
                        TextInput::make('state')
                            ->label('State / region')
                            ->maxLength(255),
                        TextInput::make('postcode')
                            ->maxLength(20),
                        Select::make('country')
                            ->options(fn () => Countries::getNames('en'))
                            ->searchable()
                            ->default(fn () => Region::country())
                            ->required(),
                    ]),

                Section::make(__('Settings'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('status')
                            ->options(ClientStatus::class)
                            ->default(ClientStatus::Active)
                            ->required(),
                        Select::make('default_payment_method')
                            ->options(fn (?Client $record) => PaymentMethods::options($record?->default_payment_method))
                            ->placeholder(__('None'))
                            ->helperText(__('Pre-selected when recording a payment.')),
                        Select::make('currency')
                            ->options(fn () => Currencies::options())
                            ->default(fn () => Currencies::base())
                            ->selectablePlaceholder(false)
                            ->disabled(fn (?Client $record) => (bool) $record?->hasBilling())
                            ->helperText(fn (?Client $record) => $record?->hasBilling()
                                ? __('Stays as it is once the client has services or payment notices.')
                                : __('Prices, payment notices and payments for this client are in this currency.'))
                            ->visible(fn () => Currencies::multiple()),
                        Toggle::make('renewal_reminders')
                            ->default(true)
                            ->helperText(__('Email reminders before a service is due.')),
                        Toggle::make('overdue_notices')
                            ->default(true)
                            ->helperText(__('Email notices once a service is overdue.')),
                        Toggle::make('informational_emails')
                            ->default(true)
                            ->helperText(__('News and offers from Messages to clients. The client can turn it off from the link in those emails.')),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        Text::make(fn (?Client $record) => TermsOfService::describeAcceptance($record?->terms_accepted_at, $record?->terms_version, signup: true))
                            ->visible(fn (?Client $record) => $record?->terms_accepted_at !== null)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
