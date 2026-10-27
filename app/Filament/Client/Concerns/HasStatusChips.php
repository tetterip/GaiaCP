<?php

namespace App\Filament\Client\Concerns;

use Closure;
use Filament\Schemas\Components\View;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chips over a client-area list, like the admin's (filament/services/quick-filters): All and one per status, each
 * with its count, setting the table's hidden filters. Chips with nothing to show are left out, and the row is hidden
 * while the list holds a single status, where it would add nothing. The page lists its chips in statusChipDefinitions().
 */
trait HasStatusChips
{
    /**
     * The chips in order: key => the filter states it sets (filter name => state, e.g. ['status' => ['value' => 'paid']]),
     * the rows it counts (from chipsQuery()) and its dot colour. 'all' sets every filter back to empty.
     *
     * @return array<string, array{label: string, filters: array<string, array<string, mixed>>, query: Closure(Builder): mixed, dot?: string}>
     */
    abstract protected function statusChipDefinitions(): array;

    /** The rows the list shows with no filter on (the client's own, in the open sidebar entry). */
    abstract protected function chipsQuery(): Builder;

    protected function statusChipsComponent(): View
    {
        return View::make('filament.services.quick-filters')
            ->visible(fn () => $this->statusChips() !== [])
            ->viewData(fn () => [
                'filters' => $this->statusChips(),
                'dots' => array_filter(array_map(fn (array $chip) => $chip['dot'] ?? null, $this->statusChipDefinitions())),
            ]);
    }

    /** @return array<string, array{label: string, count: int, active: bool}> */
    public function statusChips(): array
    {
        $chips = [];

        foreach ($this->statusChipDefinitions() as $key => $chip) {
            $query = $this->chipsQuery();
            ($chip['query'])($query);
            $chips[$key] = ['label' => $chip['label'], 'count' => $query->count(), 'active' => $this->chipIsOn($chip['filters'])];
        }

        $others = array_filter($chips, fn (array $chip, string $key) => $key !== 'all' && $chip['count'] > 0, ARRAY_FILTER_USE_BOTH);

        if (count($others) < 2 && ! collect($chips)->except('all')->contains('active', true)) {
            return [];
        }

        return array_filter($chips, fn (array $chip, string $key) => $key === 'all' || $chip['count'] > 0 || $chip['active'], ARRAY_FILTER_USE_BOTH);
    }

    /** Called by a chip in filament/services/quick-filters. */
    public function applyQuickFilter(string $key): void
    {
        $chip = $this->statusChipDefinitions()[$key] ?? null;

        if ($chip === null) {
            return;
        }

        $this->getTableFiltersForm()->fill();
        $filters = $this->getTable()->hasDeferredFilters() ? $this->tableDeferredFilters : $this->tableFilters;

        foreach ($chip['filters'] as $name => $state) {
            $filters[$name] = array_merge($filters[$name] ?? [], $state);
        }

        $this->tableDeferredFilters = $filters;
        $this->tableFilters = $filters;
        $this->updatedTableFilters();
    }

    /**
     * All, then a chip per status, for a table whose `status` SelectFilter takes one value.
     *
     * @param  list<\BackedEnum&HasLabel&HasColor>  $statuses
     * @return array<string, array{label: string, filters: array<string, array<string, mixed>>, query: Closure(Builder): mixed, dot?: string}>
     */
    protected function chipsPerStatus(string $allLabel, array $statuses): array
    {
        $chips = ['all' => ['label' => $allLabel, 'filters' => ['status' => ['value' => null]], 'query' => fn (Builder $query) => $query]];

        foreach ($statuses as $status) {
            $chips[$status->value] = [
                'label' => $status->getLabel(),
                'filters' => ['status' => ['value' => $status->value]],
                'query' => fn (Builder $query) => $query->where('status', $status),
                'dot' => $status->getColor(),
            ];
        }

        return $chips;
    }

    /** @param  array<string, array<string, mixed>>  $filters */
    private function chipIsOn(array $filters): bool
    {
        foreach ($filters as $name => $state) {
            foreach ($state as $field => $value) {
                if ((($this->tableFilters[$name][$field] ?? null) ?: null) !== ($value ?: null)) {
                    return false;
                }
            }
        }

        return true;
    }
}
