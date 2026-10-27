<?php

namespace App\Reports;

use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tld;
use App\Support\Currencies;
use App\Support\DomainName;
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

    /** @var Collection<int, Tld>|null */
    private ?Collection $tlds = null;

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
     * @return Collection<int, array{service: Service, due: CarbonImmutable, cents: int, cost: int, overdue: bool}>
     */
    public function dues(int $months = 12): Collection
    {
        $end = $this->start()->addMonths($months)->subDay();
        $dues = collect();

        $services = Service::query()->billable()->where('auto_terminate', false)->with(['client', 'server', 'product', 'domainDetails'])->get();

        foreach ($services as $service) {
            $due = $service->next_due_date;
            $cost = (int) $this->domainCost($service);

            while ($due->lessThanOrEqualTo($end)) {
                $dues->push(['service' => $service, 'due' => $due, 'cents' => Currencies::toBase($service->priceOn($due), $service->currency), 'cost' => $cost, 'overdue' => $due->lessThan($this->today)]);
                if (! $service->billing_cycle->isRecurring()) {
                    break; // a one-time service is owed once
                }

                $due = $service->billing_cycle->advance($due, $service->anchorDay());
            }
        }

        return $dues->sortBy(fn (array $d) => $d['due']->toDateString())->values();
    }

    /**
     * The forecast month by month (this month counts only what is still to come), with each service type's share.
     *
     * @return list<array{month: CarbonImmutable, count: int, cents: int, domain_costs: int, types: array<string, int>}>
     */
    public function monthly(int $months = 12): array
    {
        $dues = $this->dues($months)->reject(fn (array $d) => $d['overdue']);
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $this->start()->addMonths($i);
            $in = $dues->filter(fn (array $d) => $d['due']->isSameMonth($month));

            $rows[] = [
                'month' => $month,
                'count' => $in->count(),
                'cents' => (int) $in->sum('cents'),
                'domain_costs' => (int) $in->sum('cost'),
                'types' => collect(ServiceType::cases())
                    ->mapWithKeys(fn (ServiceType $type) => [$type->value => (int) $in->filter(fn (array $d) => $d['service']->type === $type)->sum('cents')])
                    ->all(),
            ];
        }

        return $rows;
    }

    /** @return array{count: int, cents: int} renewals past due and not paid yet */
    public function overdue(): array
    {
        $overdue = $this->dues(1)->filter(fn (array $d) => $d['overdue']);

        return ['count' => $overdue->count(), 'cents' => (int) $overdue->sum('cents')];
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

        $months = $service->billing_cycle->months();

        if ($months < 12 || $months % 12 !== 0) {
            return null;
        }

        return $this->tldOf((string) $service->domain)?->costCents('renew', intdiv($months, 12));
    }

    /**
     * Domains per TLD: how many, the yearly value of their prices and of what the registrar charges to renew them (each
     * spread over its cycle), and how many have no cost set. TLDs of tracked domains (registered elsewhere) are left out.
     *
     * @return Collection<int, array{tld: string, domains: int, yearly_cents: int, yearly_cost_cents: int, without_cost: int}>
     */
    public function byTld(): Collection
    {
        return Service::query()->billable()->where('auto_terminate', false)->where('type', ServiceType::Domain)
            ->with('domainDetails')->get()
            ->reject(fn (Service $s) => $s->domainDetails?->registration_type === DomainRegistrationType::External)
            ->groupBy(fn (Service $s) => $this->tldOf((string) $s->domain)?->tld ?? '.'.Str::afterLast((string) $s->domain, '.'))
            ->map(function (Collection $domains, string $tld) {
                $years = fn (Service $s) => max(1, $s->billing_cycle->months()) / 12;
                $costs = $domains->map(fn (Service $s) => [$s, $this->domainCost($s)]);

                return [
                    'tld' => $tld,
                    'domains' => $domains->count(),
                    'yearly_cents' => (int) round($domains->sum(fn (Service $s) => Currencies::toBase($s->price_cents, $s->currency) / $years($s))),
                    'yearly_cost_cents' => (int) round($costs->sum(fn (array $c) => $c[1] === null ? 0 : $c[1] / $years($c[0]))),
                    'without_cost' => $costs->filter(fn (array $c) => $c[1] === null)->count(),
                ];
            })
            ->sortByDesc('yearly_cents')
            ->values();
    }

    private function tldOf(string $domain): ?Tld
    {
        $this->tlds ??= Tld::query()->get()->sortByDesc(fn (Tld $t) => strlen($t->tld))->values();
        $domain = DomainName::normalize($domain);
        $ascii = DomainName::toAscii($domain);

        return $this->tlds->first(fn (Tld $t) => strlen($domain) > strlen($t->tld)
            && (str_ends_with($domain, $t->tld) || str_ends_with($ascii, DomainName::toAscii($t->tld))));
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
        $dues = $this->dues($months)->reject(fn (array $d) => $d['overdue']);

        $services = Service::query()->billable()->where('auto_terminate', false)->where('type', ServiceType::Hosting)
            ->whereNotNull('server_id')->get()
            ->groupBy('server_id');

        return Server::query()
            ->whereIn('id', $services->keys())
            ->orWhere(fn ($query) => $query->where('active', true)->where('monthly_cost_cents', '>', 0))
            ->get()
            ->map(function (Server $server) use ($services, $dues) {
                $accounts = $services->get($server->id, collect());
                $yearly = (int) round($accounts->sum(fn (Service $s) => Currencies::toBase($s->price_cents, $s->currency) * 12 / max(1, $s->billing_cycle->months())));

                return [
                    'server' => $server->name,
                    'accounts' => $accounts->count(),
                    'monthly_cents' => (int) round($yearly / 12),
                    'yearly_cents' => $yearly,
                    'forecast_cents' => (int) $dues->filter(fn (array $d) => $d['service']->server_id == $server->id && $d['service']->type === ServiceType::Hosting)->sum('cents'),
                    'cost_cents' => $server->monthly_cost_cents,
                ];
            })
            ->sortByDesc('yearly_cents')
            ->values();
    }
}
