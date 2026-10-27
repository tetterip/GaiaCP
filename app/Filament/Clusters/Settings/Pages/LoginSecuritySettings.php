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

/** How many failed logins ban an IP address and for how long, and the addresses never banned (LoginSecurity). */
class LoginSecuritySettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Security';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Login security';

    protected static ?string $slug = 'login-security';

    public static function settingsDescription(): string
    {
        return __('Ban an IP address after failed logins, for how long, and the addresses that are never banned.');
    }

    public static function keys(): array
    {
        return ['login_security.enabled', 'login_security.max_attempts', 'login_security.ban_minutes', 'login_security.allowed_ips'];
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

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['login_security.enabled'] = (bool) $values['login_security.enabled'];
        $values['login_security.max_attempts'] = (int) ($values['login_security.max_attempts'] ?? config('gaiacp.login_security.max_attempts'));
        $values['login_security.ban_minutes'] = (int) ($values['login_security.ban_minutes'] ?? config('gaiacp.login_security.ban_minutes'));
        $values['login_security.allowed_ips'] = implode("\n", preg_split('/[\s,]+/', (string) $values['login_security.allowed_ips'], flags: PREG_SPLIT_NO_EMPTY));

        return $values;
    }
}
