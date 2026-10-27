<?php

namespace App\Ai;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What the AI assistant is told about a client (Assistant::reminderText(), clientHints()): numbers and facts only, never
 * their name, contact details, address, VAT number or domain names. How they pay (from their payment notices), what they have
 * (services, prices, how full their hosting was) and what they asked for (ticket subjects without their details).
 */
class ClientFacts
{
    /** How far back the history goes, in months. */
    public const MONTHS = 24;

    public function __construct(public readonly Client $client) {}

    /**
     * The notice being written about (amount, due date, days overdue), then how the client paid the others.
     *
     * @return list<string>
     */
    public function notice(Invoice $invoice): array
    {
        $days = (int) $invoice->due_date->diffInDays(today(), false);

        return [
            'This payment notice: '.Money::format($invoice->total_cents, $invoice->currency).', due '.$invoice->due_date->toDateString()
                .($days > 0 ? ", {$days} days overdue." : ($days === 0 ? ', due today.' : ', due in '.abs($days).' days.')),
            ...$this->payments(except: $invoice),
        ];
    }

    /**
     * How the client pays: their notices of the last MONTHS months, how many were paid late and by how much, and what
     * is overdue now ($except: the notice being written about).
     *
     * @return list<string>
     */
    public function payments(?Invoice $except = null): array
    {
        $notices = Invoice::query()->where('client_id', $this->client->id)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->where('due_date', '>=', now()->subMonths(self::MONTHS))
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->get(['id', 'status', 'due_date', 'paid_on', 'total_cents', 'currency']);

        $paid = $notices->where('status', InvoiceStatus::Paid)->filter(fn (Invoice $n) => $n->paid_on !== null);
        $late = $paid->filter(fn (Invoice $n) => $n->paid_on->gt($n->due_date));
        $overdue = $notices->where('status', InvoiceStatus::Unpaid)->filter(fn (Invoice $n) => $n->due_date->lt(today()));

        $lines = ['Client since '.$this->client->created_at?->format('Y-m').'.'];
        $lines[] = $paid->isEmpty()
            ? 'No payment notices paid in the last '.self::MONTHS.' months.'
            : "Paid {$paid->count()} payment notices in the last ".self::MONTHS." months: {$late->count()} of them late"
                .($late->isNotEmpty() ? ', on average '.round($late->avg(fn (Invoice $n) => $n->due_date->diffInDays($n->paid_on))).' days late, at most '.$late->max(fn (Invoice $n) => (int) $n->due_date->diffInDays($n->paid_on)).' days' : '').'.';
        $lines[] = $overdue->isEmpty()
            ? 'No other overdue payment notices.'
            : "Other overdue payment notices: {$overdue->count()}, the oldest ".(int) $overdue->min('due_date')->diffInDays(today()).' days overdue.';

        return $lines;
    }

    /**
     * The client's services that are not over (type, product, cycle, price, since when, renewal), the warnings that a
     * hosting account was nearly full, open cancellation requests and the subjects of the last year's tickets.
     *
     * @return list<string>
     */
    public function services(): array
    {
        $services = Service::query()->with('product')->where('client_id', $this->client->id)
            ->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled])
            ->orderBy('id')->limit(40)->get();

        // Each domain name as "site 1", "site 2"…: which services go together shows, the names don't
        $sites = $services->pluck('domain')->filter()->map(fn (string $domain) => mb_strtolower($domain))->unique()->values()->flip()->map(fn (int $i) => 'site '.($i + 1));
        $site = fn (?string $domain) => $domain ? $sites[mb_strtolower($domain)] : null;

        $lines = $services->map(fn (Service $s) => '- '.Assistant::typeName($s->type).($s->product ? " \"{$s->product->name}\"" : '')
            .($s->domain ? ' for '.$site($s->domain) : '')
            .', '.$s->status->value
            .($s->billing_cycle ? ', '.$s->billing_cycle->value : '')
            .', '.Money::format($s->price_cents, $s->currency)
            .($s->start_date ? ', since '.$s->start_date->format('Y-m') : '')
            .($s->next_due_date ? ', renews '.$s->next_due_date->toDateString() : '')
            .($s->auto_terminate ? ', set to end at its renewal' : ''))->all();

        $warnings = DB::table('usage_warnings')->whereIn('service_id', $services->pluck('id'))
            ->where('created_at', '>=', now()->subYear())->orderBy('period')->get();
        foreach ($warnings as $warning) {
            $service = $services->firstWhere('id', $warning->service_id);
            $lines[] = "- Warned in {$warning->period}: {$warning->kind} of the hosting for {$site($service?->domain)} at {$warning->percent}% of its package";
        }

        $cancellations = CancellationRequest::query()->where('client_id', $this->client->id)->pending()->count();
        if ($cancellations > 0) {
            $lines[] = "- Open cancellation requests: {$cancellations}";
        }

        $tickets = Ticket::query()->where('client_id', $this->client->id)->where('created_at', '>=', now()->subYear())->latest('id')->limit(10)->get();
        if ($tickets->isNotEmpty()) {
            $lines[] = "Tickets in the last year: {$tickets->count()}";
            foreach ($tickets as $ticket) {
                $lines[] = '- '.$ticket->created_at?->format('Y-m').': '.(new TicketContext($ticket))->redact($ticket->subject, withDomains: false);
            }
        }

        return $lines !== [] ? $lines : ['No services now.'];
    }

    /** What the client could order (the products clients may order now): type, name and price per cycle. @return list<string> */
    public static function catalogue(string $currency): array
    {
        return Product::query()->clientOrderable()->orderBy('type')->orderBy('name')->limit(40)->get()
            ->map(function (Product $product) use ($currency) {
                $cycle = $product->billing_cycle;
                $price = $cycle ? $product->priceFor($cycle, $currency) : null;

                return '- '.Assistant::typeName($product->type)." \"{$product->name}\""
                    .($price !== null ? ', '.Money::format($price, $currency).' '.$cycle->value : '')
                    .($product->addon ? ' (an add-on to a service)' : '');
            })->all();
    }
}
