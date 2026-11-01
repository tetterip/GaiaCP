<?php

namespace App\Notifications;

use App\Mail\SecurityNoticeMail;
use App\Models\Passkey;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a login that a passkey was added to it (Profile → Passkeys, App\Filament\Concerns\ManagesPasskeys), so a
 * passkey added by someone else in a browser left signed in does not go unnoticed. In the look of the Forgot
 * password? email (the company's for the client area, GaiaCP's for /admin). Sent at once: there is no queue worker.
 */
class PasskeyAdded extends Notification
{
    public function __construct(public readonly Passkey $passkey, public readonly bool $client) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): SecurityNoticeMail
    {
        $name = config('gaiacp.company.name') ?: config('app.name');
        $when = $this->passkey->created_at->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i');

        $mail = (new MailMessage)
            ->subject(__('A passkey was added to your :name login', ['name' => $name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('A passkey (:device) was added to :email on :date. It logs in without a password.', ['device' => $this->passkey->name, 'email' => $notifiable->email, 'date' => $when]))
            ->line(__('If it was not you, reset your password now: that also removes every passkey of your login. Then contact us.'))
            ->action(__('Reset my password'), Filament::getPanel($this->client ? 'client' : 'admin')->getRequestPasswordResetUrl())
            ->salutation($name);

        return (new SecurityNoticeMail($mail, $this->client))->to($notifiable->email);
    }
}
