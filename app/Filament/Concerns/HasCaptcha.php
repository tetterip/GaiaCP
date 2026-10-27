<?php

namespace App\Filament\Concerns;

use App\Captcha\Captcha;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * The captcha Settings → General puts on a login, sign up or forgot password form (App\Captcha\Captcha). A submit
 * that fails resets the widget and forgets the solved token, so the next try needs a new one.
 */
trait HasCaptcha
{
    /** @return list<Component> */
    protected function captchaComponents(string $form): array
    {
        return Captcha::components($form);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $submit
     * @return T
     */
    protected function withCaptcha(callable $submit): mixed
    {
        try {
            return $submit();
        } catch (ValidationException $e) {
            Captcha::forget();
            $this->dispatch('captcha-reset');

            throw $e;
        }
    }
}
