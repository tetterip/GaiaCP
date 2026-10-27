<?php

namespace App\Notifications;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Pages\Auth\EditProfile;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Quotes\QuoteResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The clients' bell (top bar of the client area, Settings → Client area → Notifications): activity log events about a
 * client's own notices, services and tickets become a notification for its login, in its language, unless the login
 * turned Notifications off in its profile (`users.panel_notifications`) or did it themselves. Stored as
 * ClientAlert, so an admin linked to a client sees them only in the client area (PanelNotifications). Written at
 * once, no queue; services not shown in the client area are left out.
 */
class ClientAlerts
{
    public const EVENTS = [
        'invoice.created',
        'invoice.reminder_sent',
        'invoice.paid',
        'service.activated',
        'service.suspended',
        'service.unsuspended',
        'service.terminated',
        'service.auto_terminated',
        'service.cancellation_approved',
        'service.cancellation_rejected',
        'service.upgrade_approved',
        'service.upgrade_rejected',
        'service.price_change_scheduled',
        'service.usage_high',
        'reminder.sent',
        'domain.renewed',
        'ssl.ordered',
        'ssl.reissued',
        'ssl.renewed',
        'ssl.issued',
        'ticket.replied',
        'ticket.closed',
        'client.change_approved',
        'client.change_rejected',
        'quote.sent',
    ];

    public static function fromLog(ActivityLog $log): void
    {
        if (! in_array($log->event, self::EVENTS, true) || ! $log->client_id || ! config('gaiacp.client_area.notifications')) {
            return;
        }

        // A notification that can't be written never undoes what was logged.
        try {
            $user = $log->client?->user;
            if (! $user || ! $user->active || ! $user->panel_notifications || ($log->user_id && $log->user_id === $user->id)) {
                return;
            }

            if ($log->service_id && ! Service::query()->clientVisible()->whereKey($log->service_id)->exists()) {
                return;
            }

            if ($notification = self::notification($log, $user->preferredLocale())) {
                $user->notifyNow(new ClientAlert($notification->getDatabaseMessage()));
            }
        } catch (Throwable $e) {
            report($e);
        }
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
        $service = $log->service;
        $name = $service?->domain ?: $service?->label;

        [$title, $body, $icon, $color, $url] = match ($log->event) {
            'invoice.created', 'invoice.reminder_sent', 'invoice.paid' => self::invoice($log),
            'service.activated' => [
                __(':name is active', ['name' => $name]), self::product($service),
                'heroicon-o-check-circle', 'success', self::serviceUrl($service),
            ],
            'service.suspended' => [
                __(':name was suspended', ['name' => $name]),
                __('Pay any overdue payment notice, or contact us, to have it back.'),
                'heroicon-o-pause-circle', 'danger', self::serviceUrl($service),
            ],
            'service.unsuspended' => [
                __(':name is active again', ['name' => $name]), null,
                'heroicon-o-play-circle', 'success', self::serviceUrl($service),
            ],
            'service.terminated', 'service.auto_terminated' => [
                __(':name has ended', ['name' => $name]), self::product($service),
                'heroicon-o-x-circle', 'gray', self::serviceUrl($service),
            ],
            'service.cancellation_approved' => [
                __('Cancellation approved: :name', ['name' => $name]),
                $service?->status === ServiceStatus::Terminated ? null : __('It ends on :date.', ['date' => $service?->next_due_date?->format(AppServiceProvider::dateFormat())]),
                'heroicon-o-x-circle', 'info', self::serviceUrl($service),
            ],
            'service.cancellation_rejected' => [
                __('Cancellation request declined: :name', ['name' => $name]), __('Contact us if you have questions.'),
                'heroicon-o-x-circle', 'warning', self::serviceUrl($service),
            ],
            'service.upgrade_approved' => [
                __('Package change approved: :name', ['name' => $name]), self::product($service),
                'heroicon-o-arrows-up-down', 'success', self::serviceUrl($service),
            ],
            'service.upgrade_rejected' => [
                __('Package change declined: :name', ['name' => $name]), __('Contact us if you have questions.'),
                'heroicon-o-arrows-up-down', 'warning', self::serviceUrl($service),
            ],
            'service.price_change_scheduled' => [
                __('New price for :name', ['name' => $name]),
                isset($log->properties['to_cents'], $log->properties['from']) ? __('From :date: :amount', [
                    'date' => CarbonImmutable::parse($log->properties['from'])->format(AppServiceProvider::dateFormat()),
                    'amount' => Money::format((int) $log->properties['to_cents'], $service?->currency),
                ]) : null,
                'heroicon-o-tag', 'info', self::serviceUrl($service),
            ],
            'service.usage_high' => [
                __('Near its package limit: :name', ['name' => $name]),
                collect($log->properties['usage'] ?? [])->map(fn ($percent, $kind) => $kind === 'disk' ? __('Disk space :percent%', ['percent' => $percent]) : __('Bandwidth :percent%', ['percent' => $percent]))->join(', '),
                'heroicon-o-chart-pie', 'warning', self::serviceUrl($service),
            ],
            // The Upcoming Domain Renewal Notice, the day it is emailed
            'reminder.sent' => $service?->type === ServiceType::Domain ? [
                __('Domain :name renews on :date', ['name' => $name, 'date' => $service->next_due_date?->format(AppServiceProvider::dateFormat())]),
                __('Its payment notice follows before then.'),
                'heroicon-o-globe-alt', 'info', self::serviceUrl($service),
            ] : [null, null, null, null, null],
            'domain.renewed' => [
                __('Domain :name renewed', ['name' => $name]),
                isset($log->properties['years']) ? __('For :years year(s)', ['years' => (int) $log->properties['years']]) : null,
                'heroicon-o-globe-alt', 'success', self::serviceUrl($service),
            ],
            'ssl.ordered', 'ssl.reissued', 'ssl.renewed' => [
                __('Validate :name for its SSL certificate', ['name' => $name]),
                __('See what to do on the service\'s page.'),
                'heroicon-o-lock-closed', 'warning', self::serviceUrl($service),
            ],
            'ssl.issued' => [
                __('SSL certificate ready: :name', ['name' => $name]),
                __('Download it from the service\'s page.'),
                'heroicon-o-lock-closed', 'success', self::serviceUrl($service),
            ],
            'ticket.replied', 'ticket.closed' => self::ticket($log),
            'client.change_approved' => [
                __('Your changes were approved'), null,
                'heroicon-o-user', 'success', EditProfile::getUrl(panel: 'client'),
            ],
            'client.change_rejected' => [
                __('Your changes were declined'), __('Contact us if you have questions.'),
                'heroicon-o-user', 'warning', EditProfile::getUrl(panel: 'client'),
            ],
            'quote.sent' => [
                __('New quote :number', ['number' => $log->properties['number'] ?? '']),
                isset($log->properties['valid_until']) ? __('Valid until :date', ['date' => CarbonImmutable::parse($log->properties['valid_until'])->format(AppServiceProvider::dateFormat())]) : null,
                'heroicon-o-document-check', 'info', QuoteResource::getUrl(panel: 'client'),
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

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} */
    private static function invoice(ActivityLog $log): array
    {
        $invoice = isset($log->properties['invoice_id']) ? Invoice::query()->find($log->properties['invoice_id']) : null;
        if (! $invoice) {
            return [null, null, null, null, null];
        }

        $amount = Money::format($invoice->total_cents, $invoice->currency);
        $due = $invoice->due_date->format(AppServiceProvider::dateFormat());
        $url = PaymentNoticeResource::getUrl('view', ['record' => $invoice], panel: 'client');

        return match ($log->event) {
            'invoice.created' => [
                __('New payment notice :number', ['number' => $invoice->number]),
                __(':amount, due :date', ['amount' => $amount, 'date' => $due]),
                'heroicon-o-document-text', 'info', $url,
            ],
            'invoice.reminder_sent' => str_starts_with((string) ($log->properties['kind'] ?? ''), 'before:') ? [
                __('Payment notice :number is due on :date', ['number' => $invoice->number, 'date' => $due]), $amount,
                'heroicon-o-clock', 'warning', $url,
            ] : [
                __('Payment notice :number is overdue', ['number' => $invoice->number]),
                __(':amount, due :date', ['amount' => $amount, 'date' => $due]),
                'heroicon-o-exclamation-triangle', 'danger', $url,
            ],
            default => [
                __('We received your payment: :amount', ['amount' => $amount]),
                __('Payment notice :number is paid. Thank you!', ['number' => $invoice->number]),
                'heroicon-o-banknotes', 'success', $url,
            ],
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} */
    private static function ticket(ActivityLog $log): array
    {
        $ticket = isset($log->properties['ticket_id']) ? Ticket::query()->find($log->properties['ticket_id']) : null;
        if (! $ticket) {
            return [null, null, null, null, null];
        }

        return [
            $log->event === 'ticket.replied'
                ? __('New answer to ticket :number', ['number' => $ticket->number])
                : __('Ticket :number closed', ['number' => $ticket->number]),
            Str::limit((string) $ticket->subject, 160),
            'heroicon-o-lifebuoy', $log->event === 'ticket.replied' ? 'info' : 'gray',
            TicketResource::getUrl('view', ['record' => $ticket], panel: 'client'),
        ];
    }

    private static function product(?Service $service): ?string
    {
        return $service && $service->domain && $service->label !== $service->domain ? $service->label : null;
    }

    private static function serviceUrl(?Service $service): ?string
    {
        if (! $service) {
            return null;
        }

        return $service->type === ServiceType::Domain
            ? DomainResource::getUrl('view', ['record' => $service], panel: 'client')
            : ServiceResource::getUrl('view', ['record' => $service], panel: 'client');
    }
}
