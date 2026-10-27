<?php

namespace App\Ai;

use App\Enums\AdminArea;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\TicketStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reports → Ask in plain words: the questions GaiaCP can answer from its data. The AI service only picks one of them
 * and its parameters from what the admin typed (Assistant::readQuestion()); GaiaCP runs it here, read-only, and the
 * answer never goes back to the AI service. Each question needs the admin's access to its area.
 */
class DataQuestions
{
    /** The most rows an answer lists. */
    public const MAX_ROWS = 100;

    /** @return array<string, array{area: AdminArea, about: string}> what the AI service is told, in English */
    public static function catalogue(): array
    {
        return [
            'clients_owing' => ['area' => AdminArea::Billing, 'about' => 'Clients with unpaid payment notices and how much they owe. Params: min_amount (number in the currency\'s units, e.g. 100 for €100; optional), overdue_days (integer; only notices due at least that many days ago; optional, 0 = overdue only).'],
            'overdue_notices' => ['area' => AdminArea::Billing, 'about' => 'Unpaid payment notices past their due date. Params: min_days (integer, how many days overdue at least; optional).'],
            'payments_total' => ['area' => AdminArea::Billing, 'about' => 'Money received: the payments recorded between two dates, in total per currency. Params: from, to (YYYY-MM-DD), type (hosting, domain, ssl, maintenance or other; optional).'],
            'top_paying_clients' => ['area' => AdminArea::Billing, 'about' => 'The clients who paid most between two dates. Params: from, to (YYYY-MM-DD), limit (1 to 50, default 10).'],
            'services_due' => ['area' => AdminArea::Services, 'about' => 'Services whose next due date (renewal) falls between two dates. Params: from, to (YYYY-MM-DD), type (hosting, domain, ssl, maintenance or other; optional), status (pending, active, suspended; optional).'],
            'services_count' => ['area' => AdminArea::Services, 'about' => 'How many services there are, by type and status. Params: type (optional), status (pending, active, suspended, terminated, cancelled, completed; optional).'],
            'domains_expiring' => ['area' => AdminArea::Services, 'about' => 'Domain names of active or suspended services that expire within a number of days (the registrar\'s expiry, else the due date). Params: days (1 to 365).'],
            'new_clients' => ['area' => AdminArea::Clients, 'about' => 'Clients added between two dates. Params: from, to (YYYY-MM-DD).'],
            'open_tickets' => ['area' => AdminArea::Support, 'about' => 'Support tickets that are not closed. Params: status (open = waiting for us, answered = waiting for the client, on_hold; optional).'],
        ];
    }

    /** @return list<string> the questions $user may ask */
    public static function allowed(?User $user): array
    {
        return collect(self::catalogue())->filter(fn (array $q) => (bool) $user?->canUseArea($q['area']))->keys()->all();
    }

    /**
     * Runs one question with the parameters the AI service read, checked here first.
     *
     * @param  array<string, mixed>  $params
     * @return array{title: string, columns: array<string, string>, rows: list<array<string, mixed>>, total: ?string, more: bool}
     *
     * @throws AiException when the question is unknown or not for this admin
     */
    public function run(string $question, array $params, ?User $user): array
    {
        if (! in_array($question, self::allowed($user), true)) {
            throw new AiException(__('You can\'t see that part of the panel, so the assistant can\'t answer it.'));
        }

        return match ($question) {
            'clients_owing' => $this->clientsOwing(self::amount($params['min_amount'] ?? null), self::integer($params['overdue_days'] ?? null, 0, 3650)),
            'overdue_notices' => $this->overdueNotices(self::integer($params['min_days'] ?? null, 0, 3650) ?? 0),
            'payments_total' => $this->paymentsTotal(...self::period($params), type: self::type($params['type'] ?? null)),
            'top_paying_clients' => $this->topPaying(...self::period($params), limit: self::integer($params['limit'] ?? null, 1, 50) ?? 10),
            'services_due' => $this->servicesDue(...self::period($params, forward: true), type: self::type($params['type'] ?? null), status: self::status($params['status'] ?? null)),
            'services_count' => $this->servicesCount(self::type($params['type'] ?? null), self::status($params['status'] ?? null)),
            'domains_expiring' => $this->domainsExpiring(self::integer($params['days'] ?? null, 1, 365) ?? 30),
            'new_clients' => $this->newClients(...self::period($params)),
            'open_tickets' => $this->openTickets(TicketStatus::tryFrom((string) ($params['status'] ?? ''))),
        };
    }

    private function clientsOwing(?int $min, ?int $overdueDays): array
    {
        $notices = Invoice::query()->unpaid()
            ->when($overdueDays !== null, fn (Builder $q) => $q->whereDate('due_date', '<=', today()->subDays(max($overdueDays, 1))))
            ->with('client')
            ->get(['id', 'client_id', 'currency', 'total_cents', 'due_date']);

        $rows = $notices->groupBy(fn (Invoice $i) => $i->client_id.'|'.$i->currency)
            ->map(fn (Collection $group) => ['client' => $group->first()->client, 'currency' => $group->first()->currency, 'cents' => $group->sum('total_cents'), 'oldest' => $group->min('due_date')])
            ->filter(fn (array $row) => $row['client'] !== null && ($min === null || $row['cents'] >= $min))
            ->sortByDesc('cents');

        $title = match (true) {
            $min !== null && $overdueDays !== null => __('Clients who owe at least :amount on notices overdue :days days or more', ['amount' => Money::format($min, $rows->first()['currency'] ?? null), 'days' => max($overdueDays, 1)]),
            $min !== null => __('Clients who owe at least :amount', ['amount' => Money::format($min, $rows->first()['currency'] ?? null)]),
            $overdueDays !== null => __('Clients with notices overdue :days days or more', ['days' => max($overdueDays, 1)]),
            default => __('Clients who owe money'),
        };

        return $this->answer($title, ['client' => __('Client'), 'owed' => __('Owed'), 'oldest' => __('Oldest due date')], $rows->map(fn (array $row) => [
            'client' => $row['client']->name,
            'owed' => Money::format($row['cents'], $row['currency']),
            'oldest' => $row['oldest']?->format(AppServiceProvider::dateFormat()),
            'url' => ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $row['client']]) : null,
        ]), self::totals($rows->groupBy('currency')->map->sum('cents')));
    }

    private function overdueNotices(int $minDays): array
    {
        $notices = Invoice::query()->unpaid()->whereDate('due_date', '<=', today()->subDays(max($minDays, 1)))->with('client')->orderBy('due_date')->get();

        return $this->answer(__('Unpaid notices overdue :days days or more', ['days' => max($minDays, 1)]),
            ['number' => __('Number'), 'client' => __('Client'), 'total' => __('Total'), 'due' => __('Due date')],
            $notices->map(fn (Invoice $i) => [
                'number' => $i->number,
                'client' => $i->client?->name,
                'total' => Money::format($i->total_cents, $i->currency),
                'due' => $i->due_date->format(AppServiceProvider::dateFormat()),
                'url' => InvoiceResource::canAccess() ? InvoiceResource::getUrl('view', ['record' => $i]) : null,
            ]), self::totals($notices->groupBy('currency')->map->sum('total_cents')));
    }

    private function paymentsTotal(CarbonImmutable $from, CarbonImmutable $to, ?ServiceType $type): array
    {
        $payments = Payment::query()->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->when($type, fn (Builder $q) => $q->whereHas('service', fn (Builder $s) => $s->where('type', $type)))
            ->get(['currency', 'amount_cents']);

        $title = $type
            ? __(':type payments from :from to :to', ['type' => $type->getLabel(), 'from' => self::date($from), 'to' => self::date($to)])
            : __('Payments from :from to :to', ['from' => self::date($from), 'to' => self::date($to)]);

        return $this->answer($title, ['currency' => __('Currency'), 'count' => __('Payments'), 'total' => __('Total')],
            $payments->groupBy('currency')->map(fn (Collection $group, string $currency) => [
                'currency' => $currency,
                'count' => $group->count(),
                'total' => Money::format($group->sum('amount_cents'), $currency),
            ])->values(), null);
    }

    private function topPaying(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $rows = Payment::query()->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->whereNotNull('client_id')
            ->with('client')->get(['client_id', 'currency', 'amount_cents'])
            ->groupBy(fn (Payment $p) => $p->client_id.'|'.$p->currency)
            ->map(fn (Collection $group) => ['client' => $group->first()->client, 'currency' => $group->first()->currency, 'cents' => $group->sum('amount_cents')])
            ->filter(fn (array $row) => $row['client'] !== null)
            ->sortByDesc('cents')->take($limit);

        return $this->answer(__('Clients who paid most from :from to :to', ['from' => self::date($from), 'to' => self::date($to)]),
            ['client' => __('Client'), 'paid' => __('Paid')],
            $rows->map(fn (array $row) => [
                'client' => $row['client']->name,
                'paid' => Money::format($row['cents'], $row['currency']),
                'url' => ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $row['client']]) : null,
            ]), null);
    }

    private function servicesDue(CarbonImmutable $from, CarbonImmutable $to, ?ServiceType $type, ?ServiceStatus $status): array
    {
        $services = Service::query()->whereBetween('next_due_date', [$from->toDateString(), $to->toDateString()])
            ->when($type, fn (Builder $q) => $q->where('type', $type))
            ->when($status, fn (Builder $q) => $q->where('status', $status), fn (Builder $q) => $q->billable())
            ->with('client')->orderBy('next_due_date')->get();

        return $this->answer(__('Services due from :from to :to', ['from' => self::date($from), 'to' => self::date($to)]).self::filters($type, $status),
            ['service' => __('Service'), 'client' => __('Client'), 'price' => __('Price'), 'due' => __('Next due date')],
            $services->map(fn (Service $s) => [
                'service' => $s->label,
                'client' => $s->client?->name,
                'price' => Money::format($s->price_cents, $s->currency),
                'due' => $s->next_due_date?->format(AppServiceProvider::dateFormat()),
                'url' => ServiceResource::canAccess() ? ServiceResource::getUrl('edit', ['record' => $s]) : null,
            ]), self::totals($services->groupBy('currency')->map->sum('price_cents')));
    }

    private function servicesCount(?ServiceType $type, ?ServiceStatus $status): array
    {
        $counts = Service::query()
            ->when($type, fn (Builder $q) => $q->where('type', $type))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->toBase()->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get();

        return $this->answer(__('Number of services').self::filters($type, $status),
            ['type' => __('Type'), 'status' => __('Status'), 'count' => __('Services')],
            $counts->sortByDesc('total')->map(fn (object $row) => [
                'type' => ServiceType::tryFrom($row->type)?->getLabel() ?? $row->type,
                'status' => ServiceStatus::tryFrom($row->status)?->getLabel() ?? $row->status,
                'count' => (int) $row->total,
            ]), (string) $counts->sum('total'));
    }

    private function domainsExpiring(int $days): array
    {
        $until = today()->addDays($days);
        $domains = Service::query()->where('type', ServiceType::Domain)->billable()->with(['client', 'domainDetails'])->get()
            ->map(fn (Service $s) => [$s, $s->domainDetails?->expires_at ?? $s->next_due_date])
            ->filter(fn (array $pair) => $pair[1] !== null && $pair[1]->lte($until))
            ->sortBy(fn (array $pair) => $pair[1]->timestamp);

        return $this->answer(__('Domains expiring in the next :days days', ['days' => $days]),
            ['domain' => __('Domain'), 'client' => __('Client'), 'expires' => __('Expires')],
            $domains->map(fn (array $pair) => [
                'domain' => $pair[0]->domain,
                'client' => $pair[0]->client?->name,
                'expires' => $pair[1]->format(AppServiceProvider::dateFormat()),
                'url' => ServiceResource::canAccess() ? ServiceResource::getUrl('edit', ['record' => $pair[0]]) : null,
            ]), null);
    }

    private function newClients(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clients = Client::query()->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('created_at')->get();

        return $this->answer(__('Clients added from :from to :to', ['from' => self::date($from), 'to' => self::date($to)]),
            ['client' => __('Client'), 'company' => __('Company'), 'added' => __('Added')],
            $clients->map(fn (Client $c) => [
                'client' => $c->name,
                'company' => $c->company,
                'added' => $c->created_at?->format(AppServiceProvider::dateFormat()),
                'url' => ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $c]) : null,
            ]), (string) $clients->count());
    }

    private function openTickets(?TicketStatus $status): array
    {
        $tickets = Ticket::query()->where('status', '!=', TicketStatus::Closed)
            ->when($status && $status !== TicketStatus::Closed, fn (Builder $q) => $q->where('status', $status))
            ->with('client')->latest('last_reply_at')->get();

        return $this->answer($status && $status !== TicketStatus::Closed ? __('Tickets: :status', ['status' => $status->getLabel()]) : __('Tickets not closed'),
            ['subject' => __('Subject'), 'client' => __('Client'), 'status' => __('Status')],
            $tickets->map(fn (Ticket $t) => [
                'subject' => $t->subject,
                'client' => $t->client?->name ?? $t->name,
                'status' => $t->status->getLabel(),
                'url' => TicketResource::canAccess() ? TicketResource::getUrl('view', ['record' => $t]) : null,
            ]), (string) $tickets->count());
    }

    /** @param  Collection<int|string, array<string, mixed>>  $rows */
    private function answer(string $title, array $columns, Collection $rows, ?string $total): array
    {
        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows->take(self::MAX_ROWS)->values()->all(),
            'total' => $total,
            'more' => $rows->count() > self::MAX_ROWS,
        ];
    }

    /** @param  Collection<string, int>  $byCurrency */
    private static function totals(Collection $byCurrency): ?string
    {
        return $byCurrency->isEmpty() ? null : $byCurrency->map(fn (int $cents, string $currency) => Money::format($cents, $currency))->join(' + ');
    }

    private static function filters(?ServiceType $type, ?ServiceStatus $status): string
    {
        $parts = array_filter([$type?->getLabel(), $status?->getLabel()]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /**
     * The period asked, within ten years; a missing end is today (or a month on for $forward), a missing start a
     * month before the end.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private static function period(array $params, bool $forward = false): array
    {
        $parse = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? rescue(fn () => CarbonImmutable::parse($value), report: false) : null;
        $from = $parse($params['from'] ?? null);
        $to = $parse($params['to'] ?? null) ?? ($forward ? ($from ?? CarbonImmutable::today())->addMonth() : CarbonImmutable::today());
        $from ??= $forward ? CarbonImmutable::today() : $to->subMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from->max(CarbonImmutable::today()->subYears(10)), 'to' => $to->min(CarbonImmutable::today()->addYears(10))];
    }

    private static function date(CarbonImmutable $date): string
    {
        return $date->format(AppServiceProvider::dateFormat());
    }

    /** An amount in units (100 or "100.50") as cents, or null. */
    private static function amount(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value < 100000000 ? (int) round((float) $value * 100) : null;
    }

    private static function integer(mixed $value, int $min, int $max): ?int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : null;
    }

    private static function type(mixed $value): ?ServiceType
    {
        return ServiceType::tryFrom((string) $value);
    }

    private static function status(mixed $value): ?ServiceStatus
    {
        return ServiceStatus::tryFrom((string) $value);
    }
}
