<?php

namespace Plugins\TicketMail;

use App\Plugins\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/**
 * Email to ticket: a support mailbox read over IMAP every minute, or emails a mail provider posts to a webhook (Mailgun,
 * SendGrid, Postmark…); answers join their ticket, new emails open one.
 */
class TicketMailPlugin extends Plugin
{
    public function id(): string
    {
        return 'ticket-mail';
    }

    public function name(): string
    {
        return 'Email to ticket';
    }

    public function description(): string
    {
        return __('Reads your support mailbox over IMAP: a client\'s answer to a ticket email joins the ticket, and a new email opens a ticket.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return TicketMailSettings::class;
    }

    public function pages(): array
    {
        return [TicketMailSettings::class];
    }

    public function commands(): array
    {
        return [FetchTicketMail::class];
    }

    public function settingKeys(): array
    {
        return [
            'ticket_mail.host', 'ticket_mail.port', 'ticket_mail.encryption', 'ticket_mail.verify_tls', 'ticket_mail.username',
            'ticket_mail.password', 'ticket_mail.folder', 'ticket_mail.after', 'ticket_mail.department_id',
            'ticket_mail.unknown_senders', 'ticket_mail.max_per_hour', 'ticket_mail.inbound_token',
        ];
    }

    public function encryptedSettingKeys(): array
    {
        return ['ticket_mail.password', 'ticket_mail.inbound_token'];
    }

    public function webhookRoutes(): void
    {
        Route::post('ticket-mail/{token}', InboundWebhook::class)->middleware('throttle:120,1')->name('ticket-mail');
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('gaiacp:fetch-ticket-mail')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();
    }
}
