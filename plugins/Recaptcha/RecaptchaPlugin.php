<?php

namespace Plugins\Recaptcha;

use App\Captcha\CaptchaModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Google reCAPTCHA as a captcha (App\Captcha\CaptchaModule), with its keys under Settings → Google reCAPTCHA. */
class RecaptchaPlugin extends Plugin implements CaptchaModule
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function id(): string
    {
        return 'recaptcha';
    }

    public function name(): string
    {
        return 'Google reCAPTCHA';
    }

    public function description(): string
    {
        return __('Google\'s "I\'m not a robot" check on the login and sign up forms.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return RecaptchaSettings::class;
    }

    public function pages(): array
    {
        return [RecaptchaSettings::class];
    }

    public function settingKeys(): array
    {
        return ['recaptcha.site_key', 'recaptcha.secret_key'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['recaptcha.secret_key'];
    }

    public function moduleLogs(): array
    {
        return ['recaptcha' => 'Google reCAPTCHA'];
    }

    public function captchaKey(): string
    {
        return 'recaptcha';
    }

    public function captchaLabel(): string
    {
        return 'Google reCAPTCHA';
    }

    public function captchaConfigured(): bool
    {
        return filled(config('gaiacp.recaptcha.site_key')) && filled(config('gaiacp.recaptcha.secret_key'));
    }

    public function captchaWidget(): array
    {
        return [
            'script' => 'https://www.google.com/recaptcha/api.js?render=explicit&hl='.app()->getLocale(),
            'global' => 'grecaptcha',
            'options' => ['sitekey' => config('gaiacp.recaptcha.site_key')],
        ];
    }

    public function verifyCaptcha(string $token, ?string $ip): bool
    {
        $secret = (string) config('gaiacp.recaptcha.secret_key');

        try {
            $response = Http::asForm()->timeout(10)
                ->withMiddleware(ModuleLogger::middleware('recaptcha', 'verify', [$secret, $token]))
                ->post(self::VERIFY_URL, array_filter(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
