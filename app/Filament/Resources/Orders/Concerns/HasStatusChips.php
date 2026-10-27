<?php

namespace App\Filament\Resources\Orders\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chips over an orders table (OrdersTable): All, Pending, Completed and Cancelled, each with its count, setting the
 * status filter. Like the payment notices' chips (Invoices\Concerns\HasStatusChips); used by the Orders list and the
 * client page's tab, the class gives the orders it counts in statusChipsQuery().
 */
trait HasStatusChips
{
    /** @return Builder<Order> */
    abstract protected function statusChipsQuery(): Builder;

    /** The chips, to go right over the table. */
    protected function statusChipsComponent(): View
    {
        return View::make('filament.services.quick-filters')
            ->viewData(fn () => [
                'filters' => $this->statusChips(),
                'dots' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->getColor()])->all(),
            ]);
    }

    /** @return array<string, array{label: string, count: int, active: bool}> */
    public function statusChips(): array
    {
        $status = $this->tableFilters['status']['value'] ?? null;

        return ['all' => ['label' => __('All orders'), 'count' => $this->statusChipsQuery()->count(), 'active' => blank($status)]]
            + collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $chip) => [$chip->value => [
                'label' => $chip->getLabel(),
                'count' => $this->statusChipsQuery()->where('status', $chip)->count(),
                'active' => $status === $chip->value,
            ]])->all();
    }

    /** Called by a chip in filament/services/quick-filters. */
    public function applyQuickFilter(string $key): void
    {
        if ($key !== 'all' && OrderStatus::tryFrom($key) === null) {
            return;
        }

        $this->getTableFiltersForm()->fill();
        $filters = $this->getTable()->hasDeferredFilters() ? $this->tableDeferredFilters : $this->tableFilters;

        $filters['status']['value'] = $key === 'all' ? null : $key;

        $this->tableDeferredFilters = $filters;
        $this->tableFilters = $filters;
        $this->updatedTableFilters();
    }
}
