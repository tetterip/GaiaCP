<?php

namespace App\Captcha;

use App\Plugins\PluginManager;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;

/**
 * Which captcha shows on which form (Settings → General): `gaiacp.captcha.provider` = an enabled, set up captcha
 * plugin's key (null = none), `gaiacp.captcha.forms` = the FORMS it is on. A token the service accepted is
 * remembered in the session for a few minutes, since Livewire may validate the same form twice (the login's
 * two-factor step); a failed login forgets it (forget()), so every password guess needs a new one.
 */
class Captcha
{
    /** The forms it can be on (Settings → General). */
    public const FORMS = ['client_login', 'client_signup', 'client_password_reset', 'domain_search', 'contact_form', 'admin_login', 'admin_password_reset'];

    /** @return array<string, string> form => label */
    public static function formLabels(): array
    {
        return [
            'client_login' => __('Client area login'),
            'client_signup' => __('Client area sign up'),
            'client_password_reset' => __('Client area forgot password'),
            'domain_search' => __('Client area domain search'),
            'contact_form' => __('Contact form for visitors'),
            'admin_login' => __('Admin login'),
            'admin_password_reset' => __('Admin forgot password'),
        ];
    }

    private const SESSION = 'captcha.verified';

    /** @return array<string, string> key => label of the captcha plugins that are on and set up */
    public static function options(): array
    {
        return collect(app(PluginManager::class)->captchaModules())
            ->filter(fn (CaptchaModule $module) => $module->captchaConfigured())
            ->mapWithKeys(fn (CaptchaModule $module) => [$module->captchaKey() => $module->captchaLabel()])
            ->all();
    }

    /** The captcha chosen in Settings → General, while its plugin is on and set up. */
    public static function active(): ?CaptchaModule
    {
        $key = config('gaiacp.captcha.provider');

        if (blank($key)) {
            return null;
        }

        foreach (app(PluginManager::class)->captchaModules() as $module) {
            if ($module->captchaKey() === $key && $module->captchaConfigured()) {
                return $module;
            }
        }

        return null;
    }

    public static function on(string $form): bool
    {
        return self::active() !== null && in_array($form, (array) config('gaiacp.captcha.forms', []), true);
    }

    /** @return list<Component> the captcha field for this form, or nothing */
    public static function components(string $form): array
    {
        if (! self::on($form)) {
            return [];
        }

        $module = self::active();

        return [
            ViewField::make('captcha')
                ->hiddenLabel()
                ->view('filament.forms.captcha')
                ->viewData(['widget' => $module->captchaWidget()])
                ->required()
                ->validationMessages(['required' => __('Please complete the check that you are not a robot.')])
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! self::verify((string) $value)) {
                        $fail(__('The check that you are not a robot failed. Please try again.'));
                    }
                })
                ->dehydrated(false),
        ];
    }

    public static function verify(string $token): bool
    {
        $module = self::active();

        if ($module === null) {
            return true;
        }

        if ($token === '') {
            return false;
        }

        $remembered = session(self::SESSION);

        if (is_array($remembered) && hash_equals($remembered['hash'], hash('sha256', $token)) && $remembered['until'] > now()->timestamp) {
            return true;
        }

        if (! $module->verifyCaptcha($token, request()->ip())) {
            return false;
        }

        session()->put(self::SESSION, ['hash' => hash('sha256', $token), 'until' => now()->addMinutes(5)->timestamp]);

        return true;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION);
    }
}
