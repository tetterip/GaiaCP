<?php

namespace App\Events;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Server;
use App\Models\Ticket;
use App\Plugins\PluginManager;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Something that happened in GaiaCP that plugins (and Laravel listeners) may act on: one of the activity log events
 * in NAMES, raised once the log row is committed. Plugins say which ones they want in Plugin::events() and get them
 * in Plugin::handleEvent(); Event::listen(GaiaEvent::class) works too. A listener that fails never undoes the action.
 */
class GaiaEvent
{
    /** @var list<string> the activity log events raised for plugins and listeners */
    public const NAMES = [
        'order.placed',
        'order.completed',
        'client.signed_up',
        'invoice.created',
        'invoice.paid',
        'invoice.cancelled',
        'payment.recorded',
        'payment.reversed',
        'service.activated',
        'service.suspended',
        'service.unsuspended',
        'service.terminated',
        'service.auto_terminated',
        'service.product_changed',
        'service.cancellation_requested',
        'service.upgrade_requested',
        'service.addon_ordered',
        'domain.activated',
        'domain.renewed',
        'domain.expiring',
        'ticket.opened',
        'ticket.opened_by_admin',
        'ticket.client_replied',
        'ticket.replied',
        'ticket.closed',
        'lead.created',
        'lead.converted',
        'lead.lost',
        'quote.sent',
        'quote.accepted',
        'quote.declined',
        'quote.expired',
        'server.down',
        'server.up',
        'provisioning.failed',
    ];

    /** Property names never passed on (they would only matter to GaiaCP, or are secrets). */
    private const HIDDEN_PROPERTIES = '/password|passwd|secret|token|epp|auth_code|terms_version/i';

    /** @var array<string, mixed>|null built once, then the same for every listener */
    private ?array $payload = null;

    public function __construct(public readonly string $name, public readonly ActivityLog $log) {}

    /** Raises the event for a new activity log row in NAMES, after the transaction it was written in commits. */
    public static function fromLog(ActivityLog $log): void
    {
        if (! in_array($log->event, self::NAMES, true)) {
            return;
        }

        DB::afterCommit(fn () => (new self($log->event, $log))->dispatch());
    }

    /** @return array<string, string> event => what happened, in the current language */
    public static function options(): array
    {
        return [
            'order.placed' => __('Order placed'),
            'order.completed' => __('Order completed'),
            'client.signed_up' => __('Client signed up'),
            'invoice.created' => __('Payment notice issued'),
            'invoice.paid' => __('Payment notice paid'),
            'invoice.cancelled' => __('Payment notice cancelled'),
            'payment.recorded' => __('Payment recorded'),
            'payment.reversed' => __('Payment reversed'),
            'service.activated' => __('Service activated'),
            'service.suspended' => __('Service suspended'),
            'service.unsuspended' => __('Service unsuspended'),
            'service.terminated' => __('Service terminated'),
            'service.auto_terminated' => __('Service terminated at the end of its cycle'),
            'service.product_changed' => __('Service moved to another product'),
            'service.cancellation_requested' => __('Cancellation requested'),
            'service.upgrade_requested' => __('Package change requested'),
            'service.addon_ordered' => __('Add-on added by the client'),
            'domain.activated' => __('Domain activated'),
            'domain.renewed' => __('Domain renewed'),
            'domain.expiring' => __('Domain expiring'),
            'ticket.opened' => __('Ticket opened'),
            'ticket.opened_by_admin' => __('Ticket opened by an admin'),
            'ticket.client_replied' => __('Client replied to a ticket'),
            'ticket.replied' => __('Ticket answered'),
            'ticket.closed' => __('Ticket closed'),
            'lead.created' => __('Lead added'),
            'lead.converted' => __('Lead became a client'),
            'lead.lost' => __('Lead lost'),
            'quote.sent' => __('Quote sent'),
            'quote.accepted' => __('Quote accepted'),
            'quote.declined' => __('Quote declined'),
            'quote.expired' => __('Quote expired'),
            'server.down' => __('Server down'),
            'server.up' => __('Server up again'),
            'provisioning.failed' => __('Panel or registrar call failed'),
        ];
    }

    public function dispatch(): void
    {
        try {
            event($this);
        } catch (Throwable $e) {
            report($e);
        }

        app(PluginManager::class)->dispatchEvent($this);
    }

    /**
     * What happened, as plain data to pass on (e.g. as webhook JSON): the client, the service and the record the
     * event is about (payment notice, payment, order, ticket, lead or server), plus its log properties. No passwords,
     * EPP codes or card data. Money in integer cents with its currency, dates as Y-m-d, times as ISO 8601.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $log = $this->log;
        $properties = $log->properties ?? [];
        $client = $log->client;
        $service = $log->service;

        $data = [
            'event' => $this->name,
            'id' => $log->id,
            'occurred_at' => $log->created_at?->toIso8601String(),
            'description' => $log->description,
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'company' => $client->company,
                'email' => $client->email,
            ] : null,
            'service' => $service ? [
                'id' => $service->id,
                'type' => self::plain($service->type),
                'label' => $service->label,
                'domain' => $service->domain,
                'status' => self::plain($service->status),
                'product_id' => $service->product_id,
                'server_id' => $service->server_id,
                'price_cents' => $service->price_cents,
                'currency' => $service->currency,
                'billing_cycle' => self::plain($service->billing_cycle),
                'next_due_date' => self::plain($service->next_due_date),
            ] : null,
        ];

        if (isset($properties['invoice_id']) && $invoice = Invoice::query()->find($properties['invoice_id'])) {
            $data['invoice'] = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => self::plain($invoice->status),
                'date' => self::plain($invoice->date),
                'due_date' => self::plain($invoice->due_date),
                'total_cents' => $invoice->total_cents,
                'currency' => $invoice->currency,
            ];
        }

        if (isset($properties['payment_id']) && $payment = Payment::query()->find($properties['payment_id'])) {
            $data['payment'] = [
                'id' => $payment->id,
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'paid_on' => self::plain($payment->paid_on),
                'method' => self::plain($payment->method),
                'reference' => $payment->reference,
            ];
        }

        if (isset($properties['order_id']) && $order = Order::query()->find($properties['order_id'])) {
            $data['order'] = ['id' => $order->id, 'status' => self::plain($order->status)];
        }

        if (isset($properties['ticket_id']) && $ticket = Ticket::query()->find($properties['ticket_id'])) {
            $data['ticket'] = [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'status' => self::plain($ticket->status),
            ];
        }

        if (isset($properties['quote_id']) && $quote = Quote::query()->find($properties['quote_id'])) {
            $data['quote'] = [
                'id' => $quote->id,
                'number' => $quote->number,
                'status' => self::plain($quote->status),
                'total_cents' => $quote->totals()['total'],
                'currency' => $quote->currency,
                'valid_until' => self::plain($quote->valid_until),
                'order_id' => $quote->order_id,
            ];
        }

        if (isset($properties['lead_id']) && $lead = Lead::query()->find($properties['lead_id'])) {
            $data['lead'] = [
                'id' => $lead->id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'company' => $lead->company,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'source' => $lead->source,
                'status' => self::plain($lead->status),
            ];
        }

        if (isset($properties['server_id']) && $server = Server::query()->find($properties['server_id'])) {
            $data['server'] = ['id' => $server->id, 'name' => $server->name, 'hostname' => $server->hostname];
        }

        $data['properties'] = collect($properties)
            ->reject(fn ($value, $key) => preg_match(self::HIDDEN_PROPERTIES, (string) $key) === 1)
            ->map(fn ($value) => is_array($value) ? $value : self::plain($value))
            ->all() ?: null;

        return $this->payload = $data;
    }

    /** One line for a chat message or a notification: the label and the log's description. */
    public function summary(): string
    {
        return (self::options()[$this->name] ?? Str::headline($this->name)).': '.$this->log->description;
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }
}
