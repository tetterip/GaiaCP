<?php

namespace App\Chat;

use App\Events\GaiaEvent;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * An event as one short chat message, for any chat plugin to shape: an emoji, the event's label, one line of details
 * (client · service or domain · ticket · server · amount) and a link to its page in the admin area.
 */
final class ChatLine
{
    public function __construct(
        public readonly string $event,
        public readonly string $emoji,
        public readonly string $title,
        public readonly string $text,
        public readonly ?string $url,
        public readonly string $level, // info, success, warning or danger
    ) {}

    public static function from(GaiaEvent $event): self
    {
        $data = $event->payload();

        return new self(
            $event->name,
            self::emoji($event->name),
            GaiaEvent::options()[$event->name] ?? Str::headline($event->name),
            self::details($data) ?: (string) $event->log->description,
            rescue(fn () => self::url($data), report: false),
            self::level($event->name),
        );
    }

    /** A sample for a channel's Test button. */
    public static function test(): self
    {
        return new self('chat.test', '👋', __('Test message from GaiaCP'), __('If you can read this, GaiaCP can post here.'), url('/admin'), 'info');
    }

    /** @param array<string, mixed> $data */
    private static function details(array $data): string
    {
        $client = $data['client'] ?? null;
        $service = $data['service'] ?? null;
        $ticket = $data['ticket'] ?? null;
        $money = match (true) {
            isset($data['payment']) => Money::format((int) $data['payment']['amount_cents'], (string) $data['payment']['currency']),
            isset($data['invoice']) => $data['invoice']['number'].' · '.Money::format((int) $data['invoice']['total_cents'], (string) $data['invoice']['currency']),
            default => null,
        };

        return collect([
            $client ? trim(($client['company'] ?: '') ?: "{$client['first_name']} {$client['last_name']}") : null,
            $service ? ($service['domain'] ?: $service['label']) : null,
            isset($data['order']) ? __('Order #:id', ['id' => $data['order']['id']]) : null,
            $ticket ? "{$ticket['number']}: {$ticket['subject']}" : null,
            isset($data['server']) ? $data['server']['name'] : null,
            $money,
        ])->filter(fn ($part) => filled($part))->join(' · ');
    }

    /** @param array<string, mixed> $data */
    private static function url(array $data): ?string
    {
        return match (true) {
            isset($data['ticket']) => TicketResource::getUrl('view', ['record' => $data['ticket']['id']], panel: 'admin'),
            isset($data['invoice']) => InvoiceResource::getUrl('view', ['record' => $data['invoice']['id']], panel: 'admin'),
            isset($data['order']) => OrderResource::getUrl('view', ['record' => $data['order']['id']], panel: 'admin'),
            isset($data['service']) => ServiceResource::getUrl('edit', ['record' => $data['service']['id']], panel: 'admin'),
            isset($data['server']) => ServerResource::getUrl(panel: 'admin'),
            isset($data['client']) => ClientResource::getUrl('edit', ['record' => $data['client']['id']], panel: 'admin'),
            default => null,
        };
    }

    private static function emoji(string $event): string
    {
        return match (true) {
            in_array($event, ['invoice.paid', 'payment.recorded'], true) => '💶',
            str_starts_with($event, 'order.') => '🛒',
            $event === 'client.signed_up' => '👤',
            str_starts_with($event, 'invoice.') => '🧾',
            str_starts_with($event, 'ticket.') => '🎫',
            str_starts_with($event, 'domain.') => '🌐',
            $event === 'server.down', $event === 'provisioning.failed' => '🔴',
            $event === 'server.up' => '🟢',
            default => '🔔',
        };
    }

    private static function level(string $event): string
    {
        return match ($event) {
            'invoice.paid', 'payment.recorded', 'order.placed', 'order.completed', 'client.signed_up', 'lead.converted', 'service.activated', 'domain.renewed', 'server.up' => 'success',
            'server.down', 'provisioning.failed', 'payment.reversed' => 'danger',
            'service.suspended', 'service.terminated', 'service.auto_terminated', 'service.cancellation_requested', 'domain.expiring', 'ticket.opened', 'ticket.client_replied' => 'warning',
            default => 'info',
        };
    }
}
