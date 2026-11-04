<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\Registrar;
use App\Models\Tld;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use App\Support\Currencies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TLD import and pricing sync, like WHMCS's: reads what a registrar charges for each TLD (ListsTldPrices) and
 * Settings → Domain pricing → Import from registrar stores it as the TLDs' costs, with selling prices worked out
 * as cost + markup. syncCosts() is the daily run (Settings → Domains → Update registrar costs daily): it only ever
 * changes costs, never the prices clients pay, and rings the bell when a TLD starts to sell at a loss.
 *
 * Rows are plain arrays in the base currency: {tld, years, register, renew, transfer, redemption} (cents or null),
 * the costs of the term of `years` (the registrar's shortest, e.g. 2 for .gr at easy.gr).
 */
class ImportTldPrices
{
    /** How long Import from registrar keeps a registrar's reply, so the window does not ask again on every click. */
    public const CACHE_MINUTES = 15;

    /** The order Import from registrar shows the amounts in. */
    public const KINDS_SHOWN = ['register', 'renew', 'transfer'];

    /** none = to the cent, whole = up to a whole euro, 99 = up to the next x.99. */
    public const ROUNDING = ['none', 'whole', '99'];

    public function __construct(private RegistrarFactory $factory) {}

    /** @return Collection<int, Registrar> the active registrar accounts that can tell their TLD prices */
    public function registrars(): Collection
    {
        return Registrar::query()->active()->orderBy('name')->get()
            ->filter(fn (Registrar $registrar) => $this->pricer($registrar) !== null)
            ->values();
    }

    public function pricer(Registrar $registrar): ?ListsTldPrices
    {
        try {
            $client = $this->factory->forRegistrar($registrar);
        } catch (Throwable) {
            return null; // inactive, or its plugin is off
        }

        return $client instanceof ListsTldPrices ? $client : null;
    }

    /**
     * The registrar's costs, by TLD, in the base currency. A registrar that cannot list every TLD is asked about
     * $tlds, or else the TLDs it is the preferred registrar of. Terms longer than Tld::YEARS are left out.
     *
     * @param  list<string>|null  $tlds
     * @return array<string, array{tld: string, years: int, register: ?int, renew: ?int, transfer: ?int, redemption: ?int}>
     *
     * @throws ProvisioningException
     */
    public function fetch(Registrar $registrar, ?array $tlds = null): array
    {
        $pricer = $this->pricer($registrar) ?? throw new ProvisioningException("{$registrar->name} cannot tell its TLD prices.");
        $tlds = $tlds === null ? null : array_values(array_unique(array_filter(array_map(self::normalize(...), $tlds), fn (string $tld) => $tld !== '.')));

        if (! $pricer->listsAllTlds() && ! $tlds) {
            $tlds = $registrar->tlds()->pluck('tld')->all();

            if ($tlds === []) {
                throw new ProvisioningException("Name the TLDs to read from {$registrar->name}.");
            }
        }

        $rows = [];

        foreach ($pricer->tldPrices($tlds ?: null) as $price) {
            $currency = strtoupper($price->currency);

            if ($currency !== Currencies::base() && ! Currencies::get($currency)) {
                throw new ProvisioningException("{$registrar->name} gives its prices in {$currency}: add {$currency} under Settings → Currencies so its rate is known.");
            }

            if (! in_array($price->years, Tld::YEARS, true)) {
                continue;
            }

            $toBase = fn (?int $cents) => $cents === null ? null : Currencies::toBase($cents, $currency);
            $tld = self::normalize($price->tld);
            $rows[$tld] = [
                'tld' => $tld,
                'years' => $price->years,
                'register' => $toBase($price->registerCents),
                'renew' => $toBase($price->renewCents),
                'transfer' => $toBase($price->transferCents),
                'redemption' => $toBase($price->redemptionCents),
            ];
        }

        ksort($rows);

        return $rows;
    }

    /**
     * fetch(), kept for CACHE_MINUTES (plain arrays only).
     *
     * @param  list<string>|null  $tlds
     * @return array<string, array{tld: string, years: int, register: ?int, renew: ?int, transfer: ?int, redemption: ?int}>
     */
    public function cached(Registrar $registrar, ?array $tlds = null): array
    {
        return Cache::remember(self::cacheKey($registrar, $tlds), now()->addMinutes(self::CACHE_MINUTES), fn () => $this->fetch($registrar, $tlds));
    }

    /** @param  list<string>|null  $tlds */
    public static function cacheKey(Registrar $registrar, ?array $tlds): string
    {
        return 'tld-import.'.$registrar->id.'.'.md5((string) json_encode($tlds));
    }

    /**
     * The selling price for a cost: cost + $percent % + $fixedCents, rounded up as $rounding says.
     */
    public static function sellingPrice(int $costCents, float $percent, int $fixedCents, string $rounding): int
    {
        $cents = (int) ceil(round($costCents * (1 + $percent / 100) + $fixedCents, 4));

        return match ($rounding) {
            'whole' => (int) ceil($cents / 100) * 100,
            '99' => (int) ceil(($cents + 1) / 100) * 100 - 1,
            default => $cents,
        };
    }

    /**
     * Stores the chosen rows: each TLD's costs for the row's term, and selling prices from them for a new TLD (and for
     * one already listed when $options['prices']). A new TLD gets $registrar as its preferred registrar, one already
     * listed only with $options['preferred'].
     *
     * @param  list<array{tld: string, years: int, register: ?int, renew: ?int, transfer: ?int, redemption: ?int}>  $rows
     * @param  array{percent?: float|int|string|null, fixed_cents?: int|null, rounding?: string|null, prices?: bool, preferred?: bool, active?: bool}  $options
     * @return array{created: int, updated: int}
     */
    public function apply(Registrar $registrar, array $rows, array $options): array
    {
        $percent = (float) ($options['percent'] ?? 0);
        $fixed = (int) ($options['fixed_cents'] ?? 0);
        $rounding = in_array($options['rounding'] ?? null, self::ROUNDING, true) ? $options['rounding'] : 'none';
        $price = fn (int $cost) => self::sellingPrice($cost, $percent, $fixed, $rounding);
        $counts = ['created' => 0, 'updated' => 0];

        DB::transaction(function () use ($registrar, $rows, $options, $price, &$counts) {
            foreach ($rows as $row) {
                $tld = Tld::query()->where('tld', self::normalize($row['tld']))->first();
                $new = $tld === null;
                $tld ??= new Tld(['tld' => $row['tld'], 'active' => (bool) ($options['active'] ?? false), 'epp_required' => true]);
                $setPrices = $new || ($options['prices'] ?? false);
                $years = (int) $row['years'];
                $costs = $tld->costs ?? [];

                foreach (Tld::KINDS as $kind) {
                    if (($row[$kind] ?? null) === null) {
                        continue;
                    }

                    $costs["{$kind}_{$years}y_cents"] = (int) $row[$kind];

                    if ($setPrices) {
                        $tld->{"{$kind}_{$years}y_cents"} = $price((int) $row[$kind]);
                    }
                }

                if ($setPrices && ($row['redemption'] ?? null) !== null) {
                    $tld->redemption_fee_cents = $price((int) $row['redemption']);
                }

                if ($new || ($options['preferred'] ?? false)) {
                    $tld->registrar_id = $registrar->id;
                }

                $tld->costs = $costs;
                $tld->save();
                $counts[$new ? 'created' : 'updated']++;
            }
        });

        ActivityLog::record('tld.imported', sprintf(
            'TLD prices imported from %s: %d new, %d updated (%s; markup %s%% + %s, rounding %s)',
            $registrar->name, $counts['created'], $counts['updated'],
            ($options['prices'] ?? false) ? 'costs and prices' : 'costs, prices of new TLDs only',
            rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'), number_format($fixed / 100, 2, '.', ''), $rounding,
        ), properties: ['registrar_id' => $registrar->id, 'tlds' => array_column($rows, 'tld'), ...$counts]);

        return $counts;
    }

    /**
     * The daily cost sync: every active registrar that can tell its prices updates the costs of the TLDs it is the
     * preferred registrar of. Selling prices stay as they are; the bell rings for TLDs that now sell at a loss.
     *
     * @return array{changed: list<string>, losses: list<string>, failed: list<string>} "registrar: .tld" lines
     */
    public function syncCosts(bool $dryRun = false): array
    {
        $report = ['changed' => [], 'losses' => [], 'failed' => []];

        foreach ($this->registrars() as $registrar) {
            $tlds = $registrar->tlds()->get()->keyBy('tld');

            if ($tlds->isEmpty()) {
                continue;
            }

            try {
                $rows = $this->pricer($registrar)?->listsAllTlds()
                    ? $this->fetch($registrar)
                    : $tlds->keys()->chunk(50)->flatMap(fn (Collection $chunk) => $this->fetch($registrar, $chunk->values()->all()))->all();
            } catch (ProvisioningException $e) {
                $report['failed'][] = "{$registrar->name}: {$e->getMessage()}";

                if (! $dryRun) {
                    ActivityLog::record('tld.cost_sync_failed', "Registrar costs not read from {$registrar->name}: {$e->getMessage()}", properties: ['registrar_id' => $registrar->id]);
                }

                continue;
            }

            foreach ($rows as $row) {
                /** @var Tld|null $tld */
                $tld = $tlds->get($row['tld']);

                if (! $tld) {
                    continue;
                }

                $costs = $tld->costs ?? [];

                foreach (Tld::KINDS as $kind) {
                    if ($row[$kind] !== null) {
                        $costs["{$kind}_{$row['years']}y_cents"] = $row[$kind];
                    }
                }

                if ($costs == ($tld->costs ?? [])) {
                    continue;
                }

                $report['changed'][] = "{$registrar->name}: {$tld->tld}";
                $wasLoss = $tld->sellsAtALoss();
                $tld->costs = $costs;

                if (! $wasLoss && $tld->sellsAtALoss()) {
                    $report['losses'][] = $tld->tld;
                }

                if (! $dryRun) {
                    $tld->save();
                }
            }
        }

        if (! $dryRun && $report['changed'] !== []) {
            ActivityLog::record('tld.costs_synced', 'Registrar costs updated for '.implode(', ', $report['changed']), properties: ['count' => count($report['changed'])]);
        }

        if (! $dryRun && $report['losses'] !== []) {
            ActivityLog::record('tld.selling_at_loss', 'Now sold for less than the registrar charges: '.implode(', ', $report['losses']), properties: ['tlds' => $report['losses'], 'count' => count($report['losses'])]);
        }

        return $report;
    }

    /** ".com" from "COM", "com" or ".com". */
    public static function normalize(string $tld): string
    {
        return '.'.ltrim(mb_strtolower(trim($tld)), '.');
    }
}
