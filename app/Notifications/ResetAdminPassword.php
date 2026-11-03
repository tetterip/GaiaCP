<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The "Forgot password?" email of the login screens (/admin and the client area), also sent to a client from their
 * page by an admin, in the user's panel language
 * (User::preferredLocale(); a client user's falls back to their client's email language).
 * Stands in for Filament's own notification (bound in AppServiceProvider), which is queued: GaiaCP runs no
 * queue worker, so it is sent at once. Filament sends it only to users who can open that panel, and sets `url`.
 */
class ResetAdminPassword extends ResetPassword
{
    /** Text of Laravel's mail view under the button, translated in lang/<code>.json like our own. */
    public const VIEW_TEXTS = ["If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:"];

    public string $url;

    /** @param  bool  $byAdmin  sent from the client page by an admin (ClientAreaAccess::sendPasswordResetLink()), not asked for */
    public function __construct(#[\SensitiveParameter] $token, public bool $byAdmin = false)
    {
        parent::__construct($token);
    }

    protected function resetUrl($notifiable): string
    {
        return $this->url;
    }

    /** @param  User  $notifiable */
    public function toMail($notifiable): PasswordResetMail
    {
        $name = config('gaiacp.company.name') ?: config('app.name');
        // The panel it was asked from: an admin linked to a client can ask from the client area too.
        $client = $notifiable->role === UserRole::Client || Filament::getCurrentPanel()?->getId() === 'client';
        $minutes = config('auth.passwords.'.($client ? 'clients' : config('auth.defaults.passwords')).'.expire');

        $mail = (new MailMessage)
            ->subject(__('Reset your :name password', ['name' => $name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->byAdmin
                ? __('We sent you this link to choose a new password for your :name client area (:email).', ['name' => $name, 'email' => $notifiable->email])
                : ($client
                ? __('Someone asked to reset the password of your :name client area for :email.', ['name' => $name, 'email' => $notifiable->email])
                : __('Someone asked to reset the password of the :name admin panel for :email.', ['name' => $name, 'email' => $notifiable->email])))
            ->action(__('Reset password'), $this->resetUrl($notifiable))
            ->line($minutes >= 120
                ? __('The link works once and expires in :count hours.', ['count' => intdiv($minutes, 60)])
                : __('The link works once and expires in :count minutes.', ['count' => $minutes]))
            ->line($this->byAdmin
                ? __('Until you choose a new one, your password stays as it is.')
                : __('If it was not you, ignore this email: your password stays as it is.'))
            ->salutation($name);

        return (new PasswordResetMail($mail, $client))->to($notifiable->email);
    }
}
