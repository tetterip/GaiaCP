<?php

namespace App\Notifications;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Mail\SecurityNoticeMail;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a super admin that a server's or registrar's login was added or changed (App\Support\ConnectionChanges), in
 * the look of the admin's Forgot password? email. Holds no secret: only which one, who, from where and when.
 */
class ConnectionDetailsChanged extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly bool $added,
        public readonly User $by,
        public readonly string $ip,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): SecurityNoticeMail
    {
        $when = now()->timezone(config('app.timezone'))->format(AppServiceProvider::dateFormat().' H:i');
        $values = ['name' => $this->name, 'admin' => $this->by->email, 'ip' => $this->ip, 'date' => $when];

        $subject = match ([$this->kind, $this->added]) {
            ['server', true] => __('Server :name was added', $values),
            ['server', false] => __('The login of server :name was changed', $values),
            ['registrar', true] => __('Registrar account :name was added', $values),
            default => __('The login of registrar account :name was changed', $values),
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':admin made this change on :date from :ip.', $values))
            ->line(__('GaiaCP logs in to your servers and registrars with these details. If you don\'t know about this change, check the admin users and their two-factor authentication, change that admin\'s password, and check the server or registrar account itself.'))
            ->action(__('Open the activity log'), ActivityLogResource::getUrl(panel: 'admin'))
            ->salutation('GaiaCP');

        return (new SecurityNoticeMail($mail, false))->to($notifiable->email);
    }
}
