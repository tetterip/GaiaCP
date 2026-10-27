<?php

namespace App\Notifications;

use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\BackupSettings;
use App\Filament\Pages\PluginCatalogue;
use App\Filament\Pages\SystemHealth;
use App\Filament\Pages\Updates;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\CancellationRequests\CancellationRequestResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\UpgradeRequests\UpgradeRequestResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Server;
use App\Models\Ticket;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Push\WebPush;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The admins' bell (Filament database notifications, top bar): activity log events that need an admin's eye become a
 * notification for each active admin with Notifications in the panel on (`users.panel_notifications`) who may open
 * that area (none = super admins only). The admin who did it themselves is left out, and payments only show when no
 * admin recorded them (paid online). Texts are written in each admin's own language (notification() switches to it); written at once, no queue.
 */
class AdminAlerts
{
    /** @var array<string, ?AdminArea> event => the area an admin needs to see it (null = super admins) */
    public const EVENTS = [
        'order.client_placed' => AdminArea::Clients,
        'client.signed_up' => AdminArea::Clients,
        'client.change_requested' => AdminArea::Clients,
        'invoice.created' => AdminArea::Billing,
        'invoice.paid' => AdminArea::Billing,
        'payment.online_unmatched' => AdminArea::Billing,
        'provisioning.failed' => AdminArea::Services,
        'service.cancellation_requested' => AdminArea::Services,
        'service.upgrade_requested' => AdminArea::Services,
        'service.addon_ordered' => AdminArea::Services,
        'domain.expiring' => AdminArea::Services,
        'site.problems' => AdminArea::Services,
        'service.usage_high' => AdminArea::Services,
        'ticket.opened' => AdminArea::Support,
        'ticket.client_replied' => AdminArea::Support,
        'lead.follow_up' => AdminArea::Sales,
        'lead.created' => AdminArea::Sales,
        'quote.accepted' => AdminArea::Sales,
        'quote.declined' => AdminArea::Sales,
        'server.down' => null,
        'server.up' => null,
        'server.accounts_differ' => null,
        'monitoring.failed' => null,
        'blacklist.listed' => null,
        'blacklist.delisted' => null,
        'backup.failed' => null,
        'update.failed' => null,
        'plugin.update_available' => null,
        'gaiacp.update_available' => null,
        'webhook.failing' => null,
        'webhook.disabled' => null,
        'scheduler.stopped' => null,
        'scheduler.resumed' => null,
    ];

    public static function fromLog(ActivityLog $log): void
    {
        if (! array_key_exists($log->event, self::EVENTS)) {
            return;
        }

        // A notification that can't be written never undoes what was logged.
        try {
            $causer = $log->user_id ? User::query()->find($log->user_id) : null;
            if ($log->event === 'invoice.paid' && $causer?->role === UserRole::Admin) {
                return;
            }

            // A lead added by an admin needs no bell; one from the API (a form on the website) does.
            if ($log->event === 'lead.created' && $causer) {
                return;
            }

            // A panel call without a service (Servers → Log in to control panel) is about a server: super admins.
            $area = $log->event === 'provisioning.failed' && ! $log->service_id ? null : self::EVENTS[$log->event];

            $recipients = self::recipients($area, $causer);
            // A lead to contact: only the admin it is assigned to, while they still see leads.
            if ($log->event === 'lead.follow_up' && $recipients->contains('id', $log->properties['assigned_to'] ?? null)) {
                $recipients = $recipients->where('id', $log->properties['assigned_to'])->values();
            }

            foreach ($recipients as $admin) {
                if ($notification = self::notification($log, $admin->preferredLocale())) {
                    $admin->notifyNow($notification->toDatabase());
                    WebPush::notify($admin, $notification); // and to the devices where they turned on push
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, User> */
    public static function recipients(?AdminArea $area, ?User $causer = null): Collection
    {
        return User::query()->admins()->where('active', true)->where('panel_notifications', true)->get()
            ->filter(fn (User $admin) => $admin->canUseArea($area) && ! $admin->is($causer))
            ->values();
    }

    public static function notification(ActivityLog $log, string $locale): ?Notification
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return self::build($log);
        } finally {
            app()->setLocale($previous);
        }
    }

    private static function build(ActivityLog $log): ?Notification
    {
        $client = $log->client?->name;
        $service = $log->service?->domain ?: $log->service?->label;

        [$title, $body, $icon, $color, $url] = match ($log->event) {
            'order.client_placed' => [
                __('New order #:id', ['id' => $log->properties['order_id'] ?? '']),
                __(':client placed an order in the client area', ['client' => $client]),
                'heroicon-o-shopping-cart', 'info',
                isset($log->properties['order_id']) ? OrderResource::getUrl('view', ['record' => $log->properties['order_id']], panel: 'admin') : null,
            ],
            'client.signed_up' => [
                __('New client signed up'), $client,
                'heroicon-o-user-plus', 'info',
                $log->client_id ? ClientResource::getUrl('edit', ['record' => $log->client_id], panel: 'admin') : null,
            ],
            'client.change_requested' => [
                __('Client change request'),
                __(':client asked to change their details', ['client' => $client]),
                'heroicon-o-user', 'warning',
                $log->client_id ? ClientResource::getUrl('edit', ['record' => $log->client_id], panel: 'admin') : null,
            ],
            'invoice.created' => self::created($log),
            'invoice.paid' => self::paid($log),
            'payment.online_unmatched' => [
                __('Online payment to match by hand'), Str::limit($log->description, 160),
                'heroicon-o-credit-card', 'danger',
                ActivityLogResource::getUrl(parameters: ['filters' => ['event' => ['value' => $log->event]]], panel: 'admin'),
            ],
            'provisioning.failed' => [
                $service ? __('Panel or registrar call failed: :name', ['name' => $service]) : __('Panel or registrar call failed'),
                Str::limit($log->description, 160),
                'heroicon-o-exclamation-triangle', 'danger',
                $log->service_id
                    ? ServiceResource::getUrl('edit', ['record' => $log->service_id], panel: 'admin')
                    : ActivityLogResource::getUrl(parameters: ['filters' => ['event' => ['value' => $log->event]]], panel: 'admin'),
            ],
            'service.cancellation_requested' => [
                __('Cancellation request: :name', ['name' => $service]),
                $client,
                'heroicon-o-x-circle', 'warning',
                CancellationRequestResource::getUrl(panel: 'admin'),
            ],
            'service.upgrade_requested' => [
                __('Package change request: :name', ['name' => $service]),
                $client,
                'heroicon-o-arrows-up-down', 'info',
                UpgradeRequestResource::getUrl(panel: 'admin'),
            ],
            'service.addon_ordered' => [
                __('Add-on added: :name', ['name' => $service]),
                __(':client added :addon', ['client' => $client, 'addon' => $log->service?->label]),
                'heroicon-o-puzzle-piece', 'info',
                ServiceResource::getUrl('edit', ['record' => $log->properties['parent_service_id'] ?? $log->service_id], panel: 'admin'),
            ],
            'domain.expiring' => [
                __('Domain :name expires on :date', [
                    'name' => $service,
                    'date' => isset($log->properties['expires_at']) ? CarbonImmutable::parse($log->properties['expires_at'])->format(AppServiceProvider::dateFormat()) : '',
                ]),
                $client,
                'heroicon-o-globe-alt', 'warning',
                $log->service_id ? ServiceResource::getUrl('edit', ['record' => $log->service_id], panel: 'admin') : null,
            ],
            'service.usage_high' => [
                __('Near its package limit: :name', ['name' => $service]),
                collect($log->properties['usage'] ?? [])->map(fn ($percent, $kind) => $kind === 'disk' ? __('Disk space :percent%', ['percent' => $percent]) : __('Bandwidth :percent%', ['percent' => $percent]))->join(', '),
                'heroicon-o-chart-pie', 'warning',
                $log->service_id ? ServiceResource::getUrl('edit', ['record' => $log->service_id], panel: 'admin') : null,
            ],
            'ticket.opened', 'ticket.client_replied' => self::ticket($log),
            'webhook.failing', 'webhook.disabled' => [
                $log->event === 'webhook.failing'
                    ? __('Webhook :name keeps failing', ['name' => $log->properties['name'] ?? ''])
                    : __('Webhook :name switched off', ['name' => $log->properties['name'] ?? '']),
                __(':count failed tries in a row: :error', ['count' => $log->properties['failures'] ?? '', 'error' => Str::limit((string) ($log->properties['error'] ?? ''), 120)]),
                'heroicon-o-bolt-slash', 'danger',
                $log->properties['url'] ?? null,
            ],
            'site.problems' => [
                __(':count new site problem(s)', ['count' => $log->properties['count'] ?? '']),
                Str::limit(implode('; ', $log->properties['items'] ?? []), 160),
                'heroicon-o-shield-exclamation', 'warning',
                $log->properties['url'] ?? null,
            ],
            'lead.follow_up' => self::lead($log),
            'lead.created' => self::newLead($log),
            'quote.accepted', 'quote.declined' => self::quote($log),
            'server.down' => [
                __('Server :name is down', ['name' => self::serverName($log)]),
                __('Reported by :service', ['service' => self::monitoringLabel($log)]),
                'heroicon-o-signal-slash', 'danger',
                ServerResource::getUrl(panel: 'admin'),
            ],
            'server.up' => [
                __('Server :name is up again', ['name' => self::serverName($log)]),
                isset($log->properties['down_seconds'])
                    ? __('Down for :time', ['time' => CarbonInterval::seconds((int) $log->properties['down_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2])])
                    : null,
                'heroicon-o-signal', 'success',
                ServerResource::getUrl(panel: 'admin'),
            ],
            'server.accounts_differ' => [
                __(':name: :count new difference(s) with GaiaCP', ['name' => self::serverName($log), 'count' => $log->properties['count'] ?? '']),
                Str::limit(implode('; ', $log->properties['items'] ?? []), 160),
                'heroicon-o-arrows-right-left', 'warning',
                isset($log->properties['server_id']) && Server::query()->whereKey($log->properties['server_id'])->exists()
                    ? ServerResource::getUrl('compare', ['record' => $log->properties['server_id']], panel: 'admin')
                    : ServerResource::getUrl(panel: 'admin'),
            ],
            'monitoring.failed' => [
                __(':service could not be read', ['service' => self::monitoringLabel($log)]),
                Str::limit($log->description, 160),
                'heroicon-o-exclamation-triangle', 'warning',
                ServerResource::getUrl(panel: 'admin'),
            ],
            'blacklist.listed' => [
                __(':name is on a spam blacklist', ['name' => self::serverName($log)]),
                __(':ip is listed on :zone', ['ip' => $log->properties['ip'] ?? '', 'zone' => $log->properties['zone'] ?? '']),
                'heroicon-o-no-symbol', 'danger',
                ServerResource::getUrl(panel: 'admin'),
            ],
            'blacklist.delisted' => [
                __(':name is off a spam blacklist', ['name' => self::serverName($log)]),
                __(':ip is no longer listed on :zone', ['ip' => $log->properties['ip'] ?? '', 'zone' => $log->properties['zone'] ?? '']),
                'heroicon-o-check-circle', 'success',
                ServerResource::getUrl(panel: 'admin'),
            ],
            'backup.failed' => [
                __('Backup failed'), Str::limit($log->description, 160),
                'heroicon-o-archive-box-x-mark', 'danger',
                BackupSettings::getUrl(panel: 'admin'),
            ],
            'update.failed' => [
                __('Update failed'), Str::limit($log->description, 160),
                'heroicon-o-arrow-path', 'danger',
                Updates::getUrl(panel: 'admin'),
            ],
            'plugin.update_available' => [
                __(':plugin :version is out', ['plugin' => $log->properties['name'] ?? '', 'version' => $log->properties['version'] ?? '']),
                __('Installed here: :version. Update it from the plugin catalogue.', ['version' => $log->properties['installed'] ?? '']),
                'heroicon-o-puzzle-piece', 'info',
                PluginCatalogue::getUrl(panel: 'admin'),
            ],
            'gaiacp.update_available' => [
                __('GaiaCP :version is out', ['version' => $log->properties['version'] ?? '']),
                ($log->properties['git'] ?? false)
                    ? __('Installed here: :version. This copy is deployed from Git: deploy to update it.', ['version' => $log->properties['installed'] ?? ''])
                    : __('Installed here: :version. Update it under Settings → Updates.', ['version' => $log->properties['installed'] ?? '']),
                'heroicon-o-arrow-up-circle', 'info',
                Updates::getUrl(panel: 'admin'),
            ],
            'scheduler.stopped' => [
                isset($log->properties['last_run'])
                    ? __('Cron has not run since :time', ['time' => CarbonImmutable::parse($log->properties['last_run'])->format(AppServiceProvider::dateFormat().' H:i')])
                    : __('Cron has not run yet'),
                __('Payment notices, suspensions and the other daily jobs wait until it runs again.'),
                'heroicon-o-clock', 'danger',
                SystemHealth::getUrl(panel: 'admin'),
            ],
            'scheduler.resumed' => [
                __('Cron is running again'), null,
                'heroicon-o-clock', 'success',
                SystemHealth::getUrl(panel: 'admin'),
            ],
            default => [null, null, null, null, null],
        };

        if ($title === null) {
            return null;
        }

        return Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor($color)
            ->actions($url ? [
                Action::make('open')
                    ->label(__('Open'))
                    ->url($url)
                    ->markAsRead(),
            ] : []);
    }

    /**
     * A new notice for a renewal (the daily run); an order's own notice is left out, its New order alert says it.
     *
     * @return array{0: ?string, 1: ?string, 2: string, 3: string, 4: ?string}
     */
    private static function created(ActivityLog $log): array
    {
        $invoice = isset($log->properties['invoice_id']) ? Invoice::query()->find($log->properties['invoice_id']) : null;
        // An order's notice, or a client's add-on (its own bell names the notice)
        if (! $invoice || $invoice->order_id || ($log->properties['client_addon'] ?? false)) {
            return [null, null, '', '', null];
        }

        return [
            __('New payment notice :number', ['number' => $invoice->number]),
            __(':client, :amount, due :date', [
                'client' => $invoice->client?->name,
                'amount' => Money::format($invoice->total_cents, $invoice->currency),
                'date' => $invoice->due_date->format(AppServiceProvider::dateFormat()),
            ]),
            'heroicon-o-document-text', 'info',
            InvoiceResource::getUrl('view', ['record' => $invoice], panel: 'admin'),
        ];
    }

    /** @return array{0: ?string, 1: ?string, 2: string, 3: string, 4: ?string} */
    private static function paid(ActivityLog $log): array
    {
        $invoice = isset($log->properties['invoice_id']) ? Invoice::query()->find($log->properties['invoice_id']) : null;
        if (! $invoice) {
            return [null, null, '', '', null];
        }

        return [
            __('Payment received: :amount', ['amount' => Money::format($invoice->total_cents, $invoice->currency)]),
            __('Payment notice :number, :client', ['number' => $invoice->number, 'client' => $invoice->client?->name]),
            'heroicon-o-banknotes', 'success',
            InvoiceResource::getUrl('view', ['record' => $invoice], panel: 'admin'),
        ];
    }

    /** @return array{0: ?string, 1: ?string, 2: string, 3: string, 4: ?string} */
    private static function ticket(ActivityLog $log): array
    {
        $ticket = isset($log->properties['ticket_id']) ? Ticket::query()->find($log->properties['ticket_id']) : null;
        if (! $ticket) {
            return [null, null, '', '', null];
        }

        return [
            $log->event === 'ticket.opened'
                ? __('New ticket :number', ['number' => $ticket->number])
                : __('Client replied to :number', ['number' => $ticket->number]),
            Str::limit(trim($ticket->requesterName().': '.$ticket->subject, ': '), 160),
            'heroicon-o-lifebuoy', $log->event === 'ticket.opened' ? 'info' : 'warning',
            TicketResource::getUrl('view', ['record' => $ticket], panel: 'admin'),
        ];
    }

    /** @return array{0: ?string, 1: ?string, 2: string, 3: string, 4: ?string} */
    private static function lead(ActivityLog $log): array
    {
        $lead = isset($log->properties['lead_id']) ? Lead::query()->find($log->properties['lead_id']) : null;
        if (! $lead || ! $lead->status->isOpen()) {
            return [null, null, '', '', null];
        }

        return [
            __('Contact :name today', ['name' => $lead->displayName()]),
            Str::limit(implode(' · ', array_filter([$lead->phone, $lead->email, $lead->notes])), 160) ?: null,
            'heroicon-o-phone', 'info',
            LeadResource::getUrl('edit', ['record' => $lead], panel: 'admin'),
        ];
    }

    private static function newLead(ActivityLog $log): array
    {
        $lead = isset($log->properties['lead_id']) ? Lead::query()->find($log->properties['lead_id']) : null;
        if (! $lead) {
            return [null, null, '', '', null];
        }

        return [
            __('New lead: :name', ['name' => $lead->displayName()]),
            Str::limit(implode(' · ', array_filter([$lead->email, $lead->phone, $lead->notes])), 160) ?: null,
            'heroicon-o-user-plus', 'info',
            LeadResource::getUrl('edit', ['record' => $lead], panel: 'admin'),
        ];
    }

    private static function quote(ActivityLog $log): array
    {
        $quote = isset($log->properties['quote_id']) ? Quote::query()->find($log->properties['quote_id']) : null;
        if (! $quote) {
            return [null, null, '', '', null];
        }

        return $log->event === 'quote.accepted'
            ? [
                __('Quote :number accepted', ['number' => $quote->number]),
                __(':name, :amount', ['name' => $quote->recipientName(), 'amount' => $quote->totalText()]),
                'heroicon-o-check-badge', 'success',
                QuoteResource::getUrl('edit', ['record' => $quote], panel: 'admin'),
            ]
            : [
                __('Quote :number declined', ['number' => $quote->number]),
                implode(': ', array_filter([$quote->recipientName(), $quote->decline_reason])),
                'heroicon-o-x-circle', 'warning',
                QuoteResource::getUrl('edit', ['record' => $quote], panel: 'admin'),
            ];
    }

    private static function serverName(ActivityLog $log): string
    {
        $id = $log->properties['server_id'] ?? null;

        return ($id ? Server::query()->whereKey($id)->value('name') : null) ?? (string) ($log->properties['monitor'] ?? '');
    }

    private static function monitoringLabel(ActivityLog $log): string
    {
        $key = (string) ($log->properties['module'] ?? '');

        return app(PluginManager::class)->monitoringModule($key)?->monitoringLabel() ?? $key;
    }
}
