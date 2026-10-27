<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\LeadStatus;
use App\Filament\Clusters\Reports\IncomeReportPage;
use App\Models\Lead;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/** The leads added in a period by source: how many, how many became clients, were lost or are still open. */
class LeadSources extends IncomeReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Lead sources';

    protected static ?string $slug = 'lead-sources';

    /** Y-m-d */
    #[Url]
    public string $from = '';

    /** Y-m-d */
    #[Url]
    public string $until = '';

    public function mount(): void
    {
        if (! $this->date($this->from) || ! $this->date($this->until)) {
            $this->preset('this_year');
        }
    }

    public static function reportDescription(): string
    {
        return __('Leads added in a period by where they came from, and how many became clients.');
    }

    /** @return array<string, string> */
    public function presets(): array
    {
        return [
            'this_month' => __('This month'),
            'last_month' => __('Last month'),
            'this_year' => __('This year'),
            'last_year' => __('Last year'),
        ];
    }

    public function preset(string $preset): void
    {
        $today = today()->toImmutable();

        [$from, $until] = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            default => [$today->startOfYear(), $today->endOfYear()],
        };

        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            return $value === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }

    public function controls(): ?string
    {
        return 'filament.reports.period';
    }

    public function note(): ?string
    {
        return __('Leads by the day they were added. Won = became a client; conversion = won out of those no longer open.');
    }

    protected function csvName(): string
    {
        return "lead-sources-{$this->from}-{$this->until}.csv";
    }

    public function report(): array
    {
        $from = $this->date($this->from) ?? today()->startOfYear()->toImmutable();
        $until = $this->date($this->until) ?? today()->endOfYear()->toImmutable();

        $sources = Lead::query()
            ->whereBetween('created_at', [$from->startOfDay(), $until->endOfDay()])
            ->get(['source', 'status'])
            ->groupBy(fn (Lead $lead) => $lead->source ?? '')
            ->map(fn ($leads, string $source) => [
                'source' => $source !== '' ? $source : __('Unknown'),
                'count' => $leads->count(),
                'won' => $leads->where('status', LeadStatus::Won)->count(),
                'lost' => $leads->where('status', LeadStatus::Lost)->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $rate = fn (int $won, int $lost) => $won + $lost > 0 ? round($won / ($won + $lost) * 100).'%' : '—';
        $row = fn (array $s) => [$s['source'], (string) $s['count'], (string) $s['won'], (string) $s['lost'], (string) ($s['count'] - $s['won'] - $s['lost']), $rate($s['won'], $s['lost'])];
        $won = (int) $sources->sum('won');
        $lost = (int) $sources->sum('lost');
        $count = (int) $sources->sum('count');

        return [
            'columns' => [
                ['label' => __('Source')],
                ['label' => __('Leads'), 'end' => true],
                ['label' => __('Won'), 'end' => true],
                ['label' => __('Lost'), 'end' => true],
                ['label' => __('Open'), 'end' => true],
                ['label' => __('Conversion'), 'end' => true],
            ],
            'rows' => $sources->map(fn (array $s) => ['cells' => $row($s)])->all(),
            'totals' => [__('Total'), (string) $count, (string) $won, (string) $lost, (string) ($count - $won - $lost), $rate($won, $lost)],
            'empty' => __('No leads were added in this period.'),
            'csv' => $sources->map($row)->all(),
        ];
    }
}
