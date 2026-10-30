<?php

namespace App\Mail;

use App\Enums\MailTemplate;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\ClientAreaLinks;
use App\Support\Locales;
use App\Support\MailTemplates;
use App\Support\Money;
use App\Support\ProductOptions;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Sends a template to a client when the template is switched on. Called after the action it
 * reports on has succeeded; a failed send is logged and never undoes or interrupts that action.
 */
class ClientMailer
{
    /**
     * @param  array<string, string|HtmlString|null>|Closure(): array<string, string|HtmlString|null>  $values  a closure
     *                                                                                                          is called in the client's language
     * @param  bool  $evenIfOff  the admin asked for this one email (e.g. the signup email box)
     * @param  Invoice|Ticket|null  $invoice  the payment notice or ticket it is about, for its client area link (else the service, else Home)
     * @param  array{subject: string, body: string}|null  $text  the admin's text for this one email instead of the template's
     * @param  list<array{name: string, data: string, mime: string}>  $files  attached files
     */
    public function send(MailTemplate $template, Client $client, array|Closure $values = [], ?Service $service = null, bool $evenIfOff = false, Invoice|Ticket|null $invoice = null, ?array $text = null, array $files = []): bool
    {
        if ((! $evenIfOff && ! MailTemplates::enabled($template)) || blank($client->email)) {
            return false;
        }

        $mail = new ClientMail($template, $client, Locales::using($client->preferredLocale(), fn () => value($values)));
        // A ticket's sender who is not a client has no client area to go to
        $mail->clientAreaUrl = $template->linksToClientArea() && $client->exists ? ClientAreaLinks::for($client, $invoice ?? $service) : null;
        // …but has the ticket's secret page, where they read and answer it (Ticket::publicUrl())
        if ($invoice instanceof Ticket && ! $client->exists && $template->linksToClientArea() && ($url = $invoice->publicUrl()) !== null) {
            $mail->clientAreaUrl = $url;
            $mail->linkLabel = Locales::using($client->preferredLocale(), fn () => __('View your ticket'));
        }
        $mail->ticket = $invoice instanceof Ticket ? $invoice : null;
        $mail->previewText = $text;
        $mail->files = $files;

        try {
            $pending = Mail::to($client->email);
            if ($bcc = config('gaiacp.reminders.bcc')) {
                $pending->bcc($bcc);
            }
            $pending->send($mail);
        } catch (Throwable $e) {
            rescue(fn () => EmailLog::failed($client->email, $mail->envelope()->subject, $e, $client, $template, config('gaiacp.reminders.bcc')), report: false);
            Log::error('Client email failed', ['template' => $template->value, 'client_id' => $client->id, 'error' => $e->getMessage()]);
            ActivityLog::record('email.failed', "{$template->label()} to {$client->email} failed: {$e->getMessage()}", $service, $client, ['template' => $template->value]);

            return false;
        }

        ActivityLog::record('email.sent', "{$template->label()} sent to {$client->email}", $service, $client, ['template' => $template->value]);

        return true;
    }

    /** @param  array<string, string|HtmlString|null>|Closure(): array<string, string|HtmlString|null>  $extra  a closure is called in the client's language */
    public function service(MailTemplate $template, Service $service, array|Closure $extra = []): bool
    {
        return $this->send($template, $service->client, fn () => $this->serviceValues($service) + value($extra), $service);
    }

    public function hostingWelcome(Service $service): bool
    {
        $server = $service->server;
        $port = app(PluginManager::class)->serverModule($server?->type)?->clientPort() ?? 2083;

        return $this->service(MailTemplate::HostingWelcome, $service, [
            'package' => $service->package,
            'username' => $service->username,
            'password' => $service->password,
            'server_name' => $server?->name,
            'server_hostname' => $server?->hostname,
            'server_ip' => $server?->shared_ip,
            'control_panel_url' => $server ? "https://{$server->hostname}:{$port}" : null,
        ]);
    }

    public function domain(MailTemplate $template, Service $service, ?int $years = null): bool
    {
        $details = $service->domainDetails;

        return $this->service($template, $service, [
            'registration_years' => (string) ($years ?? $service->registrationYears()),
            'expiry_date' => $details?->expires_at?->format(AppServiceProvider::dateFormat()),
            'nameservers' => new HtmlString(collect($details?->nameservers ?: config('gaiacp.domains.nameservers', []))->filter()->map(fn ($ns) => e($ns))->join("<br>\n")),
        ]);
    }

    public function orderConfirmation(Order $order): bool
    {
        $order->loadMissing('services', 'client');

        return $this->send(MailTemplate::OrderConfirmation, $order->client, fn () => [
            'order_number' => (string) $order->id,
            'order_date' => $order->created_at?->format(AppServiceProvider::dateFormat()),
            'order_items' => MailTemplates::table([__('Item'), __('Billing cycle'), __('Amount')], $order->services->map(fn (Service $s) => [
                trim($s->label.' '.$s->domain.(($options = ProductOptions::describe($s->options)) !== '' ? ' · '.$options : '')),
                $s->billing_cycle?->getLabel(),
                Money::format($s->firstPaymentCents(), $s->currency),
            ])),
            'order_total' => $order->describeTotal(),
        ]);
    }

    /** @return array<string, string|null> */
    private function serviceValues(Service $service): array
    {
        return [
            'service' => $service->label,
            'domain' => $service->domain,
            'amount' => Money::format($service->price_cents, $service->currency),
            'billing_cycle' => $service->billing_cycle?->getLabel(),
            'next_due_date' => $service->next_due_date?->format(AppServiceProvider::dateFormat()),
        ];
    }
}
