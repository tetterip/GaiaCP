<?php

namespace App\Reports;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tld;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the active and suspended services will bring in if every renewal is paid: each service's price on each
 * due date from its next one, a cycle at a time (anchored like a payment moves it). Services set to
 * auto-terminate end at their next due date, so they add nothing. Cycles already past due are the overdue amount.
 * Amounts are the services' prices: without tax when tax is added on top of them, in the base currency at today's rate.
 * A domain's renewal also carries what the registrar charges for it (its TLD's renew cost for that term), when known.
 */
final class IncomeForecast
{
    private CarbonImmutable $today;

    /** @var array<int, list<array{service_id: int, type: ServiceType, server_id: int|null, due: CarbonImmutable, cents: int, cost: int, overdue: bool}>> months => entries() */
    private array $entries = [];

    /** @var array<string, int|null> "ending|months" => renewCost() */
    private array $costs = [];

    public function __construct(?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse($today ?? today())->startOfDay();
    }

    /** First day of the first month in the forecast (this month). */
    public function start(): CarbonImmutable
    {
        return $this->today->startOfMonth();
    }

    /**
     * Every renewal due from today until the end of the last month, plus the cycles already overdue.
     *
     * @return Collection<int, array{service_id: int, type: ServiceType, server_id: int|null, due: CarbonImmutable, cents: int, cost: int, overdue: bool}>
     */
    public function dues(int $months = 12): Collection
    {
        return collect($this->entries($months))->sortBy(fn (array $d) => $d['due']->toDateString())->values();
    }

    /**
     * The forecast month by month (this month counts only what is still to come), with each service type's share.
     *
     * @return list<array{month: CarbonImmutable, count: int, cents: int, domain_costs: int, types: array<string, int>}>
     */
    public function monthly(int $months = 12): array
    {
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $this->start()->addMonths($i);
            $rows[$month->format('Y-m')] = [
                'month' => $month,
                'count' => 0,
                'cents' => 0,
                'domain_costs' => 0,
                'types' => array_fill_keys(array_map(fn (ServiceType $type) => $type->value, ServiceType::cases()), 0),
            ];
        }

        foreach ($this->entries($months) as $due) {
            if ($due['overdue'] || ! isset($rows[$key = $due['due']->format('Y-m')])) {
                continue;
            }

            $rows[$key]['count']++;
            $rows[$key]['cents'] += $due['cents'];
            $rows[$key]['domain_costs'] += $due['cost'];
            $rows[$key]['types'][$due['type']->value] += $due['cents'];
        }

        return array_values($rows);
    }

    /** @return array{count: int, cents: int} renewals past due and not paid yet */
    public function overdue(): array
    {
        // The cycles past due are the same whatever the horizon: reuse one already worked out.
        $overdue = array_filter($this->entries($this->entries ? array_key_first($this->entries) : 1), fn (array $d) => $d['overdue']);

        return ['count' => count($overdue), 'cents' => (int) array_sum(array_column($overdue, 'cents'))];
    }

    /** What the active servers cost a month (their Monthly cost, in the base currency). */
    public function serverCosts(): int
    {
        return (int) Server::query()->where('active', true)->sum('monthly_cost_cents');
    }

    /**
     * What the registrar charges to renew a domain for one of its cycles (its TLD's renew cost for that many years),
     * in the base currency; null for other services, tracked domains, cycles that are not whole years and TLDs
     * without that cost.
     */
    public function domainCost(Service $service): ?int
    {
        if ($service->type !== ServiceType::Domain || $service->domainDetails?->registration_type === DomainRegistrationType::External) {
            return null;
        }

        return $this->renewCost((string) $service->domain, $service->billing_cycle->months());
    }

    /** The TLD's renew cost for a domain renewed every $months (whole years only), looked up once per ending and term. */
    private function renewCost(string $domain, int $months): ?int
    {
        if ($months < 12 || $months % 12 !== 0) {
            return null;
        }

        $key = Str::after($domain, '.').'|'.$months;

        return array_key_exists($key, $this->costs) ? $this->costs[$key] : $this->costs[$key] = $this->tldOf($domain)?->costCents('renew', intdiv($months, 12));
    }

    /**
     * Domains per TLD: how many, the yearly value of their prices and of what the registrar charges to renew them (each
     * spread over its cycle), and how many have no cost set. TLDs of tracked domains (registered elsewhere) are left out.
     *
     * @return Collection<int, array{tld: string, domains: int, yearly_cents: int, yearly_cost_cents: int, without_cost: int}>
     */
    public function byTld(): Collection
    {
        $tlds = [];
        $rows = Service::query()->billable()->where('auto_terminate', false)->where('services.type', ServiceType::Domain)
            ->leftJoin('domains', 'domains.service_id', '=', 'services.id')
            ->where(fn ($query) => $query->whereNull('domains.registration_type')->orWhere('domains.registration_type', '!=', DomainRegistrationType::External))
            ->toBase()->get(['services.domain', 'services.price_cents', 'services.currency', 'services.billing_cycle']);

        return $rows
            ->groupBy(function (object $row) use (&$tlds) {
                $ending = Str::after((string) $row->domain, '.');

                return $tlds[$ending] ??= $this->tldOf((string) $row->domain)?->tld ?? '.'.Str::afterLast((string) $row->domain, '.');
            })
            ->map(function (Collection $domains, string $tld) {
                $months = fn (object $row) => BillingCycle::from($row->billing_cycle)->months();
                $years = fn (object $row) => max(1, $months($row)) / 12;
                $costs = $domains->map(fn (object $row) => [$row, $this->renewCost((string) $row->domain, $months($row))]);

                return [
                    'tld' => $tld,
                    'domains' => $domains->count(),
                    'yearly_cents' => (int) round($domains->sum(fn (object $row) => Currencies::toBase((int) $row->price_cents, $row->currency) / $years($row))),
                    'yearly_cost_cents' => (int) round($costs->sum(fn (array $c) => $c[1] === null ? 0 : $c[1] / $years($c[0]))),
                    'without_cost' => $costs->filter(fn (array $c) => $c[1] === null)->count(),
                ];
            })
            ->sortByDesc('yearly_cents')
            ->values();
    }

    private function tldOf(string $domain): ?Tld
    {
        return Tld::match($domain, activeOnly: false);
    }

    /**
     * The renewals due until the end of the last month (and the overdue ones), unsorted, worked out once per horizon.
     * Only the columns the forecast reads are loaded: a forecast covers every billable service.
     *
     * @return list<array{service_id: int, type: ServiceType, server_id: int|null, due: CarbonImmutable, cents: int, cost: int, overdue: bool}>
     */
    private function entries(int $months): array
    {
        if (isset($this->entries[$months])) {
            return $this->entries[$months];
        }

        // Plain rows, and dates as Y-m-d strings (they compare in order) made into one Carbon per day: with thousands of
        // services, models and Carbon arithmetic for every cycle took seconds (advance() is BillingCycle::advance())
        $end = $this->start()->addMonths($months)->subDay()->toDateString();
        $today = $this->today->toDateString();
        $days = $rates = $cycles = $types = $dues = [];

        $rows = Service::query()->billable()->where('auto_terminate', false)
            ->leftJoin('domains', 'domains.service_id', '=', 'services.id')
            ->select(['services.id', 'services.type', 'services.server_id', 'services.domain', 'services.currency', 'services.billing_cycle',
                'services.price_cents', 'services.new_price_cents', 'services.new_price_from', 'services.next_due_date', 'services.start_date',
                'domains.registration_type'])
            ->toBase()
            ->lazyById(1000, 'services.id', 'id');

        foreach ($rows as $row) {
            $cycle = $cycles[$row->billing_cycle] ??= BillingCycle::from($row->billing_cycle);
            $type = $types[$row->type] ??= ServiceType::from($row->type);
            $step = $cycle->isRecurring() ? $cycle->months() : 0;
            $due = substr((string) $row->next_due_date, 0, 10);
            $anchor = (int) substr((string) $row->start_date, 8, 2);
            $cost = $type === ServiceType::Domain && $row->registration_type !== DomainRegistrationType::External->value
                ? (int) $this->renewCost((string) $row->domain, $cycle->months()) : 0;
            $rate = $rates[$row->currency] ??= Currencies::rate($row->currency);
            $cents = (int) round($row->price_cents / $rate);
            $from = $row->new_price_cents !== null && $row->new_price_from !== null ? substr((string) $row->new_price_from, 0, 10) : null;
            $newCents = $from !== null ? (int) round($row->new_price_cents / $rate) : $cents;
            $serverId = $row->server_id === null ? null : (int) $row->server_id;

            while ($due <= $end) {
                // = Service::priceOn($due), in the base currency
                $dues[] = ['service_id' => (int) $row->id, 'type' => $type, 'server_id' => $serverId, 'due' => $days[$due] ??= CarbonImmutable::createFromFormat('!Y-m-d', $due),
                    'cents' => $from !== null && $due >= $from ? $newCents : $cents, 'cost' => $cost, 'overdue' => $due < $today];
                if ($step === 0) {
                    break; // a one-time service is owed once
                }

                $due = self::advance($due, $step, $anchor);
            }
        }

        return $this->entries[$months] = $dues;
    }

    /** BillingCycle::advance() on a Y-m-d string: $months later, on the anchor day or the month's last day. */
    private static function advance(string $date, int $months, int $anchor): string
    {
        $month = (int) substr($date, 5, 2) - 1 + $months;
        $year = (int) substr($date, 0, 4) + intdiv($month, 12);
        $month = $month % 12 + 1;
        $last = $month === 2 ? (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28) : ([4 => 30, 6 => 30, 9 => 30, 11 => 30][$month] ?? 31);

        return sprintf('%04d-%02d-%02d', $year, $month, min($anchor > 0 ? $anchor : (int) substr($date, 8, 2), $last));
    }

    /**
     * Hosting revenue per server: accounts, the yearly value of their prices (a price spread over 12 months), the
     * renewals due in the forecast months, and the server's monthly cost. Servers without billed accounts are left
     * out, unless they are active and cost something.
     *
     * @return Collection<int, array{server: string, accounts: int, monthly_cents: int, yearly_cents: int, forecast_cents: int, cost_cents: int|null}>
     */
    public function byServer(int $months = 12): Collection
    {
        $forecast = [];

        foreach ($this->entries($months) as $due) {
            if (! $due['overdue'] && $due['type'] === ServiceType::Hosting && $due['server_id'] !== null) {
                $forecast[$due['server_id']] = ($forecast[$due['server_id']] ?? 0) + $due['cents'];
            }
        }

        // Summed in the database per server, currency and cycle
        $services = Service::query()->billable()->where('auto_terminate', false)->where('type', ServiceType::Hosting)->whereNotNull('server_id')
            ->toBase()->selectRaw('server_id, currency, billing_cycle, count(*) as accounts, sum(price_cents) as cents')
            ->groupBy('server_id', 'currency', 'billing_cycle')->get()
            ->groupBy('server_id');

        return Server::query()
            ->whereIn('id', $services->keys())
            ->orWhere(fn ($query) => $query->where('active', true)->where('monthly_cost_cents', '>', 0))
            ->get()
            ->map(function (Server $server) use ($services, $forecast) {
                $groups = $services->get($server->id, collect());
                $yearly = (int) round($groups->sum(fn (object $g) => Currencies::toBase((int) $g->cents, $g->currency) * 12 / max(1, BillingCycle::from($g->billing_cycle)->months())));

                return [
                    'server' => $server->name,
                    'accounts' => (int) $groups->sum('accounts'),
                    'monthly_cents' => (int) round($yearly / 12),
                    'yearly_cents' => $yearly,
                    'forecast_cents' => (int) ($forecast[$server->id] ?? 0),
                    'cost_cents' => $server->monthly_cost_cents,
                ];
            })
            ->sortByDesc('yearly_cents')
            ->values();
    }
}
