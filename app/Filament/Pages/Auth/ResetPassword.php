<?php

namespace App\Filament\Pages\Auth;

use App\Actions\ClientAreaAccess;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * The page a password reset link opens, in /admin and the client area. Filament sends a browser that is signed in
 * to the panel's home without the form; the link is signed and was emailed to the login, so this browser is signed
 * out of the panel instead (an admin looking as the client leaves the client area; /admin stays signed in) and the
 * form shows.
 */
class ResetPassword extends BaseResetPassword
{
    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        if (Filament::auth()->check()) {
            if (Filament::getCurrentPanel()?->getId() === 'client' && ClientAreaAccess::viewing()) {
                app(ClientAreaAccess::class)->stopViewing();
            } else {
                Filament::auth()->logout(); // only this panel's guard: the other panel's sign-in stays
            }
        }

        $this->token = $token ?? request()->query('token');

        $this->form->fill([
            'email' => $email ?? request()->query('email'),
        ]);
    }
}
