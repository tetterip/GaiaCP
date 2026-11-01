<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Filament\Resources\IpBans\IpBanResource;
use App\Support\LoginSecurity;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\IpUtils;
use UnitEnum;

/**
 * How many failed logins ban an IP address and for how long, the addresses never banned (LoginSecurity), and the
 * ways to log in besides the password (App\SignIn\SignIn).
 */
class LoginSecuritySettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Login security';

    protected static ?string $slug = 'login-security';

    public static function settingsDescription(): string
    {
        return __('Ban an IP address after failed logins, for how long, the addresses that are never banned, and passkeys and sign-in links.');
    }

    public static function keys(): array
    {
        return [
            'login_security.enabled', 'login_security.max_attempts', 'login_security.ban_minutes', 'login_security.allowed_ips',
            'sign_in.passkeys', 'sign_in.email_link', 'sign_in.admin_email_link',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Failed logins'))
                    ->description(__('Wrong passwords and two-factor codes on the admin and client area logins count. A banned address can\'t open the login, sign up or forgot password pages until the ban ends; anyone already logged in stays in.'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('login_security.enabled')
                            ->label('Ban IP addresses after failed logins')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('login_security.max_attempts')
                            ->label('Failed logins before a ban')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->visible(fn (Get $get) => (bool) $get('login_security.enabled')),
                        TextInput::make('login_security.ban_minutes')
                            ->label('Ban length')
                            ->suffix(__('minutes'))
                            ->helperText(__('Failed logins are also counted over this many minutes.'))
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(43200)
                            ->visible(fn (Get $get) => (bool) $get('login_security.enabled')),
                    ])
                    ->footerActions([Action::make('openBans')->label('Open banned IPs')->link()->url(fn () => IpBanResource::getUrl())]),
                Section::make(__('Other ways to log in'))
                    ->description(__('Besides the password, on the admin and client area logins. Google is a plugin: Settings → Google sign-in.'))
                    ->schema([
                        Toggle::make('sign_in.passkeys')
                            ->label('Passkeys')
                            ->helperText(__('Log in with a fingerprint, face or the screen lock instead of a password. Each admin and client adds their own from their profile; two-factor authentication is not asked after a passkey, which already needs the device and its unlock.')),
                        Toggle::make('sign_in.email_link')
                            ->label('Sign-in link by email in the client area')
                            ->helperText(__('"Email me a sign-in link" on the client area\'s login: a link that works once, for 15 minutes. Two-factor authentication is still asked when it is on.')),
                        Toggle::make('sign_in.admin_email_link')
                            ->label('Sign-in link by email for admins')
                            ->helperText(__('The same on /admin\'s login. Anyone who can read an admin\'s mailbox could then log in, so keep two-factor authentication on.')),
                    ]),
                Section::make(__('Never banned'))
                    ->schema([
                        Textarea::make('login_security.allowed_ips')
                            ->label('Allowed IP addresses')
                            ->rows(4)
                            ->placeholder("203.0.113.10\n2001:db8:1234::/48")
                            ->helperText(fn () => __('One address or range (CIDR) per line, such as your office. Your address now is :ip.', ['ip' => request()->ip()]))
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                foreach (preg_split('/[\s,]+/', (string) $value, flags: PREG_SPLIT_NO_EMPTY) as $entry) {
                                    if (! self::validEntry($entry)) {
                                        $fail(__(':entry is not an IP address or range.', ['entry' => $entry]));

                                        return;
                                    }
                                }
                            }),
                    ]),
            ]);
    }

    private static function validEntry(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits <= $max && IpUtils::checkIp($address, $entry));
    }

    protected function forForm(array $values): array
    {
        $values['login_security.enabled'] = (bool) $values['login_security.enabled'];
        $values['login_security.allowed_ips'] = implode("\n", LoginSecurity::allowList());

        foreach (['sign_in.passkeys', 'sign_in.email_link', 'sign_in.admin_email_link'] as $key) {
            $values[$key] = (bool) $values[$key];
        }

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['login_security.enabled'] = (bool) $values['login_security.enabled'];

        foreach (['sign_in.passkeys', 'sign_in.email_link', 'sign_in.admin_email_link'] as $key) {
            $values[$key] = (bool) ($values[$key] ?? false);
        }

        $values['login_security.max_attempts'] = (int) ($values['login_security.max_attempts'] ?? config('gaiacp.login_security.max_attempts'));
        $values['login_security.ban_minutes'] = (int) ($values['login_security.ban_minutes'] ?? config('gaiacp.login_security.ban_minutes'));
        $values['login_security.allowed_ips'] = implode("\n", preg_split('/[\s,]+/', (string) $values['login_security.allowed_ips'], flags: PREG_SPLIT_NO_EMPTY));

        return $values;
    }
}
