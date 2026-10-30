<?php

namespace Plugins\Turnstile;

use App\Captcha\CaptchaModule;
use App\Plugins\Plugin;
use App\Support\ModuleLogger;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Cloudflare Turnstile as a captcha (App\Captcha\CaptchaModule), with its keys under Settings → Cloudflare Turnstile. */
class TurnstilePlugin extends Plugin implements CaptchaModule
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function id(): string
    {
        return 'turnstile';
    }

    public function name(): string
    {
        return 'Cloudflare Turnstile';
    }

    public function description(): string
    {
        return __('Cloudflare\'s privacy-friendly check that a visitor is not a robot, on the login and sign up forms.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return TurnstileSettings::class;
    }

    public function pages(): array
    {
        return [TurnstileSettings::class];
    }

    public function settingKeys(): array
    {
        return ['turnstile.site_key', 'turnstile.secret_key'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['turnstile.secret_key'];
    }

    public function moduleLogs(): array
    {
        return ['turnstile' => 'Cloudflare Turnstile'];
    }

    public function captchaKey(): string
    {
        return 'turnstile';
    }

    public function captchaLabel(): string
    {
        return 'Cloudflare Turnstile';
    }

    public function captchaConfigured(): bool
    {
        return filled(config('gaiacp.turnstile.site_key')) && filled(config('gaiacp.turnstile.secret_key'));
    }

    public function captchaWidget(): array
    {
        return [
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            'global' => 'turnstile',
            // flexible: as wide as the form (300 px at least) instead of a fixed 300 px
            'options' => ['sitekey' => config('gaiacp.turnstile.site_key'), 'language' => app()->getLocale(), 'size' => 'flexible'],
        ];
    }

    public function verifyCaptcha(string $token, ?string $ip): bool
    {
        $secret = (string) config('gaiacp.turnstile.secret_key');

        try {
            $response = Http::asForm()->timeout(10)
                ->withMiddleware(ModuleLogger::middleware('turnstile', 'verify', [$secret, $token]))
                ->post(self::VERIFY_URL, array_filter(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
