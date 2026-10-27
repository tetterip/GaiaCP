<?php

namespace App\Filament\Concerns;

use App\Support\LoginSecurity;
use Illuminate\Validation\ValidationException;

/**
 * The login, sign up and forgot password pages under App\Support\LoginSecurity: a banned address that still has the
 * form open is sent back to the page (BlockBannedIps shows the ban there), and on a login every wrong password or
 * two-factor code counts towards a ban.
 */
trait BlocksBannedIps
{
    /** True when this address is banned: the page reloads into the ban page and the submit does nothing. */
    protected function redirectIfBanned(?string $url): bool
    {
        if (LoginSecurity::banFor(request()->ip()) === null) {
            return false;
        }

        $this->redirect($url ?? '/');

        return true;
    }

    /**
     * @template T
     *
     * @param  string  $panel  admin or client
     * @param  callable(): T  $submit
     * @return T|null
     */
    protected function countingFailedLogins(string $panel, callable $submit): mixed
    {
        $url = filament()->getLoginUrl();

        if ($this->redirectIfBanned($url)) {
            return null;
        }

        try {
            return $submit();
        } catch (ValidationException $e) {
            if (self::isFailedLogin($e) && LoginSecurity::recordFailure(request()->ip(), $panel, $this->data['email'] ?? null) !== null) {
                $this->redirect($url);

                return null;
            }

            throw $e;
        }
    }

    /** A wrong email / password (Filament's "These credentials do not match") or a wrong two-factor code. */
    private static function isFailedLogin(ValidationException $e): bool
    {
        $errors = $e->errors();

        return in_array(trans('filament-panels::auth/pages/login.messages.failed'), $errors['data.email'] ?? [], true)
            || collect(array_keys($errors))->contains(fn (string $key) => str_starts_with($key, 'data.multiFactor.'));
    }
}
