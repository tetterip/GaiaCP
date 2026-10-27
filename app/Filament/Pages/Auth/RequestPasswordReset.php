<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Password;

/**
 * "Forgot password?" on the login screen. Filament's page, except that it answers the same whether or not the
 * address is an admin who can log in (and when a link went out a minute ago), so it can't be used to find admins.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    use BlocksBannedIps;
    use HasCaptcha;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            ...$this->captchaComponents('admin_password_reset'),
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
            ->body(__('If this address belongs to an admin who can log in, a link to choose a new password is on its way.'))
            ->success();
    }
}
