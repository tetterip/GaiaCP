<?php

namespace App\Filament\Client\Pages\Auth;

use App\Actions\ClientSignup;
use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use App\Models\User;
use App\Support\ClientFields;
use App\Support\Region;
use App\Support\TermsOfService;
use Closure;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;
use Symfony\Component\Intl\Countries;

/**
 * Sign up in the client area, only while Settings → Client area → New clients can sign up is on (else a 404 and no
 * link on the login page): the client's details (the registrant contact for domains, so the address is asked for)
 * and a password. ClientSignup makes the client and its login, and the email address must be confirmed before
 * anything opens. Coming from a sign-in provider (ClientSignup::pending()), the confirmed address is fixed and no
 * password is asked. The captcha Settings → General picks shows below the password, just before the terms of service to
 * accept (TermsOfService) at the end.
 */
class Register extends BaseRegister
{
    use BlocksBannedIps;
    use HasCaptcha;

    protected Width|string|null $maxWidth = Width::TwoExtraLarge;

    public function mount(): void
    {
        abort_unless(ClientSignup::enabled(), 404);

        parent::mount();

        if ($pending = ClientSignup::pending()) {
            $this->form->fill([
                'first_name' => $pending['first_name'] ?? null,
                'last_name' => $pending['last_name'] ?? null,
                'email' => $pending['email'],
            ] + $this->form->getRawState());
        }
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Create your client account');
    }

    public function form(Schema $schema): Schema
    {
        $provider = ClientSignup::pending()['provider'] ?? null;

        return $schema
            ->components([
                Callout::make(__('Signing up with :provider', ['provider' => $provider]))
                    ->description(__(':provider confirmed your email address. Fill in the rest to finish.', ['provider' => $provider]))
                    ->info()
                    ->visible($provider !== null),
                Section::make(__('Contact'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextInput::make('first_name')
                                ->label('First name')
                                ->required()
                                ->maxLength(255)
                                ->autofocus(),
                            TextInput::make('last_name')
                                ->label('Last name')
                                ->required()
                                ->maxLength(255),
                            $this->getEmailFormComponent(),
                            TextInput::make('phone')
                                ->label('Phone number')
                                ->tel()
                                ->required()
                                ->maxLength(50)
                                ->helperText(__('With country code, e.g. +30 6912345678.')),
                            TextInput::make('company')
                                ->label('Company name')
                                ->maxLength(255),
                        ]),
                    ]),
                Section::make(__('Address'))
                    ->description(__('It appears on your payment notices and as the owner of your domains.'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextInput::make('address1')
                                ->label('Address 1')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('address2')
                                ->label('Address 2')
                                ->maxLength(255),
                            TextInput::make('city')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('postcode')
                                ->required()
                                ->maxLength(20),
                            TextInput::make('state')
                                ->label('State / region')
                                ->maxLength(255),
                            Select::make('country')
                                ->options(fn () => Countries::getNames(app()->getLocale()))
                                ->searchable()
                                ->default(fn () => Region::country())
                                ->required(),
                        ]),
                    ]),
                Section::make(__('Tax details'))
                    ->description(__('Only if you need them on your payment notices.'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 3])->schema([
                            TextInput::make('vat_number')
                                ->label('VAT number')
                                ->maxLength(50),
                            TextInput::make('tax_office')
                                ->label('Tax office')
                                ->maxLength(255),
                            TextInput::make('profession')
                                ->label('Profession')
                                ->maxLength(255),
                        ]),
                    ]),
                Section::make(__('More details'))
                    ->visible(fn () => ClientFields::editable()->isNotEmpty())
                    ->schema([Grid::make(['default' => 1, 'sm' => 2])->schema(fn () => ClientFields::components(ClientFields::editable()))]),
                Section::make(__('Password'))
                    ->description(__('You log in with your email address and this password.'))
                    ->visible($provider === null)
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            $this->getPasswordFormComponent(),
                            $this->getPasswordConfirmationFormComponent(),
                        ]),
                    ]),
                ...$this->captchaComponents('client_signup'),
                TermsOfService::checkbox(),
            ]);
    }

    public function register(): ?RegistrationResponse
    {
        if ($this->redirectIfBanned(filament()->getRegistrationUrl())) {
            return null;
        }

        return $this->withCaptcha(fn () => parent::register());
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email address')
            ->email()
            ->required()
            ->maxLength(255)
            // From a sign-in provider the address it confirmed is used (from the session, never from the form)
            ->disabled(fn () => ClientSignup::pending() !== null)
            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                if (ClientSignup::emailTaken((string) $value)) {
                    $fail(__('This email address already has an account here. Log in, use "Forgot password?" or contact us.'));
                }
            });
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Password')
            ->rule(Password::min(10)->letters()->numbers())
            ->dehydrateStateUsing(fn (#[SensitiveParameter] $state) => $state); // hashed by the User model
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $pending = ClientSignup::pending();

        if ($pending !== null) {
            abort_if(ClientSignup::emailTaken($pending['email']), 409);
            $data['email'] = $pending['email'];
        }

        $user = app(ClientSignup::class)->register($data, $pending === null ? $data['password'] : null, $pending['provider'] ?? null, acceptedTerms: (bool) ($data['accept_terms'] ?? false));
        session()->forget(ClientSignup::PENDING);

        return $user;
    }

    protected function sendEmailVerificationNotification(Model $user): void
    {
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }
}
