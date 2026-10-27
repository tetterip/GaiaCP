<?php

namespace App\Filament\Client\Pages\Auth;

use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Password;

/**
 * "Forgot password?" of the client area: only client users who can log in get a link (Filament checks
 * canAccessPanel()), and the answer is the same for any address so it can't be used to find clients.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    use BlocksBannedIps;
    use HasCaptcha;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            ...$this->captchaComponents('client_password_reset'),
        ]);
    }

    public function request(): void
    {
        if ($this->redirectIfBanned(filament()->getRequestPasswordResetUrl())) {
            return;
        }

        $this->withCaptcha(fn () => parent::request());
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        return in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)
            ? $this->getSentNotification($status)
            : parent::getFailureNotification($status);
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__('Check your email'))
            ->body(__('If this address can log in to the client area, a link to choose a new password is on its way.'))
            ->success();
    }
}
