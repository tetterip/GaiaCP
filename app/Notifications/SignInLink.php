<?php

namespace App\Notifications;

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\SignIn\EmailLinks;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Email me a sign-in link" (EmailLinks), in the user's panel language and in the look of the Forgot password? email
 * (the company's for the client area, GaiaCP's for /admin). Sent at once: there is no queue worker.
 */
class SignInLink extends Notification
{
    public function __construct(public readonly string $url, public readonly bool $client) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  User  $notifiable */
    public function toMail(object $notifiable): PasswordResetMail
    {
        $name = config('gaiacp.company.name') ?: config('app.name');

        $mail = (new MailMessage)
            ->subject(__('Your :name sign-in link', ['name' => $name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->client
                ? __('Press the button to log in to your :name client area as :email, without a password.', ['name' => $name, 'email' => $notifiable->email])
                : __('Press the button to log in to the :name admin panel as :email, without a password.', ['name' => $name, 'email' => $notifiable->email]))
            ->action(__('Log in'), $this->url)
            ->line(__('The link works once and expires in :count minutes.', ['count' => EmailLinks::MINUTES]))
            ->line(__('If you did not ask for it, ignore this email: nobody can log in without opening it.'))
            ->salutation($name);

        return (new PasswordResetMail($mail, $this->client))->to($notifiable->email);
    }
}
