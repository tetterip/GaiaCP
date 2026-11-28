<?php

namespace App\Filament\Client\Actions;

use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Forms\PasswordInput;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Provisioning\ControlsPower;
use App\Provisioning\OffersShortcuts;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use App\Provisioning\WordPressInstall;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * What a client can do on their own hosting account, each behind its switch under Settings → Client area. Panel calls
 * go through ServiceLifecycle like the admin's Account menu, so they are logged the same way (with the client's login).
 */
class HostingSelfService
{
    /** @return list<Action> */
    public static function actions(): array
    {
        return [self::openWebsite(), self::login(), ...array_map(self::power(...), ['start', 'shutdown', 'reboot']), self::changePassword()];
    }

    /** An active hosting account on a server whose panel plugin is on. */
    public static function onPanel(Service $record): bool
    {
        return $record->type === ServiceType::Hosting
            && $record->status === ServiceStatus::Active
            && $record->server !== null
            && filled($record->username)
            && app(PluginManager::class)->serverModules()->has($record->server->type);
    }

    public static function canLogIn(Service $record): bool
    {
        return config('gaiacp.client_area.hosting_login') && self::onPanel($record);
    }

    /**
     * The panel's Quick shortcuts the client can open (OffersShortcuts: cPanel, Plesk), each through the panel login
     * with ?to=<key>; none while "Log in to the panel" is off.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function shortcuts(Service $record): array
    {
        if (! self::canLogIn($record)) {
            return [];
        }

        $panel = rescue(fn () => app(ProvisionerFactory::class)->forServer($record->server), null, report: false);

        return $panel instanceof OffersShortcuts ? $panel->shortcuts() : [];
    }

    /** The WordPress section: an active account on a panel that manages WordPress (WP Toolkit), while its switch is on. */
    public static function canUseWordPress(Service $record): bool
    {
        return config('gaiacp.client_area.hosting_wordpress') && self::onPanel($record)
            && app(ServiceLifecycle::class)->managesWordPress($record);
    }

    /**
     * Install WordPress on the account's domain (or a folder of it), with the administrator the client picks; WP Toolkit
     * finishes it in a minute or two, then the site shows in the list with its Log in button.
     */
    public static function installWordPress(): Action
    {
        return Action::make('installWordPress')
            ->label('Install WordPress')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('gray')
            ->visible(fn (Service $record) => self::canUseWordPress($record))
            ->modalHeading(__('Install WordPress'))
            ->modalDescription(fn (Service $record) => __('A new WordPress site on :domain. Its files and database are added to your hosting account.', ['domain' => $record->domain]))
            ->fillForm(fn (Service $record) => ['email' => $record->client?->email])
            ->schema([
                TextInput::make('path')
                    ->label('Folder')
                    ->prefix(fn (Service $record) => $record->domain.'/')
                    ->placeholder(__('empty for the domain itself'))
                    ->maxLength(100)
                    ->regex('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/')
                    ->helperText(fn (Service $record) => __('To install it in a folder, e.g. blog for :domain/blog. The folder should be empty.', ['domain' => $record->domain])),
                TextInput::make('title')
                    ->label('Site title')
                    ->required()
                    ->maxLength(100),
                TextInput::make('username')
                    ->label('Administrator username')
                    ->required()
                    ->minLength(3)
                    ->maxLength(60)
                    ->regex('/^[A-Za-z0-9_.@-]+$/')
                    ->notIn(['admin', 'administrator', 'root'])
                    ->helperText(__('You sign in to WordPress with it. Avoid admin: it is the first name attackers try.')),
                PasswordInput::make('password')
                    ->label('Administrator password')
                    ->required()
                    ->rule(Password::min(10)->letters()->mixedCase()->numbers())
                    ->helperText(__('At least 10 characters, with capital and small letters and numbers.')),
                TextInput::make('email')
                    ->label('Administrator email')
                    ->email()
                    ->required()
                    ->maxLength(190),
            ])
            ->modalSubmitActionLabel(__('Install WordPress'))
            ->action(function (Service $record, array $data, Component $livewire) {
                try {
                    app(ServiceLifecycle::class)->installWordPress($record, new WordPressInstall(
                        path: (string) ($data['path'] ?? ''),
                        title: $data['title'],
                        username: $data['username'],
                        password: $data['password'],
                        email: $data['email'],
                        language: WordPressInstall::languageFor(app()->getLocale()),
                    ));
                } catch (ProvisioningException) {
                    Notification::make()
                        ->title(__('We could not install WordPress.'))
                        ->body(__('Please try again later, or contact us.'))
                        ->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title(__('WordPress is being installed'))
                    ->body(__('It takes a minute or two. Then it shows in the list, with a button that signs you in.'))
                    ->success()->persistent()->send();

                $livewire->dispatch('wordpress-installed');
            });
    }

    /** Start, Shut down, Restart of their own machine (ControlsPower: a Proxmox VPS), when its plugin lets clients. */
    public static function canControlPower(Service $record): bool
    {
        if (! self::onPanel($record)) {
            return false;
        }

        $panel = rescue(fn () => app(ProvisionerFactory::class)->forServer($record->server), null, report: false);

        return $panel instanceof ControlsPower && $panel->clientsControlPower();
    }

    public static function canChangePassword(Service $record): bool
    {
        return config('gaiacp.client_area.hosting_password') && self::onPanel($record);
    }

    /** "cPanel", "Plesk": the panel the account is on, as the client knows it ("cPanel / WHM" is the admin's side). */
    public static function panelName(Service $record): string
    {
        $label = app(PluginManager::class)->serverModule($record->server?->type)?->label();

        return $label ? Str::before($label, ' / ') : __('control panel');
    }

    /** The site itself, in a new tab: hosting with a domain, once set up (not a VPS, whose domain is only its name). */
    private static function openWebsite(): Action
    {
        return Action::make('openWebsite')
            ->label('Open website')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('gray')
            ->visible(fn (Service $record) => $record->type === ServiceType::Hosting && filled($record->domain) && $record->status !== ServiceStatus::Pending
                && ! app(ServiceLifecycle::class)->isMachine($record))
            ->url(fn (Service $record) => 'https://'.$record->domain, shouldOpenInNewTab: true);
    }

    private static function login(): Action
    {
        return Action::make('panelLogin')
            ->label(fn (Service $record) => __('Log in to :panel', ['panel' => self::panelName($record)]))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (Service $record) => self::canLogIn($record))
            ->url(fn (Service $record) => route('filament.client.services.panel-login', ['service' => $record->id]), shouldOpenInNewTab: true);
    }

    private static function power(string $action): Action
    {
        [$label, $icon, $done] = match ($action) {
            'start' => [__('Start'), Heroicon::OutlinedPlay, __('Your VPS is starting.')],
            'shutdown' => [__('Shut down'), Heroicon::OutlinedPower, __('Your VPS was shut down.')],
            'reboot' => [__('Restart'), Heroicon::OutlinedArrowPath, __('Your VPS is restarting.')],
        };

        return Action::make("power_{$action}")
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (Service $record) => self::canControlPower($record))
            ->requiresConfirmation($action !== 'start')
            ->modalDescription($action === 'start' ? null : __('Programs running on it stop, and anything not saved is lost.'))
            ->action(function (Service $record) use ($action, $done) {
                try {
                    app(ServiceLifecycle::class)->power($record, $action);
                } catch (ProvisioningException) {
                    Notification::make()
                        ->title(__('That did not work.'))
                        ->body(__('Try again in a few minutes. Contact us if it keeps failing.'))
                        ->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title($done)->success()->send();
            });
    }

    private static function changePassword(): Action
    {
        return Action::make('changePassword')
            ->label('Change password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->visible(fn (Service $record) => self::canChangePassword($record))
            ->modalDescription(fn (Service $record) => match (true) {
                app(ServiceLifecycle::class)->isMachine($record) => __('The password of :user inside your VPS, and of your :panel login :username.', [
                    'user' => rescue(fn () => app(ProvisionerFactory::class)->forService($record)->machineUser(), 'root', report: false),
                    'panel' => self::panelName($record),
                    'username' => $record->username,
                ]),
                app(ServiceLifecycle::class)->sharedLogin($record) !== null => __('The password of :username, the FTP and SSH user of :domain.', ['username' => $record->username, 'domain' => $record->domain]),
                default => __('The password of :username, for :panel, FTP and SSH. Programs that log in with the old one (an FTP program, for example) will need the new one.', ['username' => $record->username, 'panel' => self::panelName($record)]),
            })
            ->schema([
                PasswordInput::make('password')
                    ->label('New password')
                    ->required()
                    ->rule(Password::min(10)->letters()->mixedCase()->numbers())
                    ->helperText(__('At least 10 characters, with capital and small letters and numbers.')),
                Checkbox::make('shared_login')
                    ->label(fn (Service $record) => __('Also change my :panel login password (username: :login)', ['panel' => self::panelName($record), 'login' => app(ServiceLifecycle::class)->sharedLogin($record)]))
                    ->helperText(fn (Service $record) => __('You sign in to :panel with the username :login, not :username. It is the same for all your services there.', ['panel' => self::panelName($record), 'login' => app(ServiceLifecycle::class)->sharedLogin($record), 'username' => $record->username]))
                    ->visible(fn (Service $record) => app(ServiceLifecycle::class)->sharedLogin($record) !== null),
            ])
            ->modalSubmitActionLabel(__('Change password'))
            ->action(function (Service $record, array $data) {
                try {
                    app(ServiceLifecycle::class)->changePassword($record, $data['password'], (bool) ($data['shared_login'] ?? false));
                } catch (ProvisioningException) {
                    Notification::make()
                        ->title(__('We could not change the password.'))
                        ->body(__('The server may not accept it: try a longer one, or try again later. Contact us if it keeps failing.'))
                        ->danger()->persistent()->send();

                    return;
                }

                $restart = app(ServiceLifecycle::class)->passwordWaitsForRestart($record);

                Notification::make()
                    ->title(__('Password changed'))
                    ->body($restart ? __('Inside your VPS the new password works after you restart it.') : null)
                    ->success()->persistent($restart)->send();
            });
    }
}
