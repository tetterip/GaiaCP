<?php

namespace App\Filament\Concerns;

use App\Captcha\Captcha;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

/**
 * The captcha Settings → General puts on a login, sign up or forgot password form (App\Captcha\Captcha). A submit
 * that fails resets the widget and forgets the solved token, so the next try needs a new one.
 *
 * On a login the two-factor step submits the password form again, captcha included: its solved token is kept for as
 * long as the session while that step shows (Captcha::keep()). If it is gone all the same (the session ended), the
 * login goes back to the password step with a notice, instead of failing on the hidden captcha with nothing shown.
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
            $result = $submit();
        } catch (ValidationException $e) {
            Captcha::forget();
            $this->dispatch('captcha-reset');

            if ($this->inTwoFactorStep() && array_key_exists('data.captcha', $e->errors())) {
                $this->userUndertakingMultiFactorAuthentication = null;

                Notification::make()->warning()
                    ->title(__('The sign-in took too long'))
                    ->body(__('Complete the check that you are not a robot and sign in again.'))
                    ->persistent()
                    ->send();

                return null;
            }

            throw $e;
        }

        if ($this->inTwoFactorStep()) {
            Captcha::keep();
        }

        return $result;
    }

    /** Whether a login shows its two-factor step (Filament's Login keeps who is signing in until the code is given). */
    private function inTwoFactorStep(): bool
    {
        return property_exists($this, 'userUndertakingMultiFactorAuthentication') && filled($this->userUndertakingMultiFactorAuthentication);
    }
}
