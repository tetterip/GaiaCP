<?php

namespace App\Captcha;

/**
 * A captcha service a plugin adds (Google reCAPTCHA, Cloudflare Turnstile): its widget on the login, sign up and
 * forgot password forms Settings → General picks, and the check of the token it gives. App\Captcha\Captcha decides
 * which one shows where.
 */
interface CaptchaModule
{
    /** Stable key, stored in `gaiacp.captcha.provider`. */
    public function captchaKey(): string;

    /** Its name in the Settings → General dropdown. */
    public function captchaLabel(): string;

    /** Whether its keys are filled in; one that isn't is not offered and not shown. */
    public function captchaConfigured(): bool;

    /**
     * The widget, rendered by resources/views/filament/forms/captcha.blade.php: the script to load (explicit
     * rendering), the global it defines with render(element, options) and reset(id), and the render options
     * (site key, language, …); the callbacks are added there.
     *
     * @return array{script: string, global: string, options: array<string, mixed>}
     */
    public function captchaWidget(): array;

    /** Asks the service whether this token is a solved challenge (false on any failure). */
    public function verifyCaptcha(string $token, ?string $ip): bool;
}
