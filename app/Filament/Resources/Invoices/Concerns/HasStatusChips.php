<?php

namespace App\Filament\Resources\Invoices\Concerns;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chips over a payment notices table (InvoicesTable): All, Unpaid, Paid, Cancelled and Overdue, each with its count,
 * setting the status / overdue filters so they and the filter form agree. Used by the Payment notices list and the
 * client page's tab; the class gives the notices it counts in statusChipsQuery().
 */
trait HasStatusChips
{
    public const STATUS_CHIPS = ['all', 'unpaid', 'paid', 'cancelled', 'overdue'];

    /** @return Builder<Invoice> */
    abstract protected function statusChipsQuery(): Builder;

    /** The chips, to go right over the table. */
    protected function statusChipsComponent(): View
    {
        return View::make('filament.services.quick-filters')
            ->viewData(fn () => [
                'filters' => $this->statusChips(),
                'dots' => ['unpaid' => 'warning', 'paid' => 'success', 'cancelled' => 'gray', 'overdue' => 'danger'],
            ]);
    }

    /** @return array<string, array{label: string, count: int, active: bool}> */
    public function statusChips(): array
    {
        $status = $this->tableFilters['status']['value'] ?? null;
        $overdue = (bool) ($this->tableFilters['overdue']['isActive'] ?? false);
        $only = fn (InvoiceStatus $chip) => ! $overdue && $status === $chip->value;

        return [
            'all' => ['label' => __('All notices'), 'count' => $this->statusChipsQuery()->count(), 'active' => ! $overdue && blank($status)],
            'unpaid' => ['label' => __('Unpaid'), 'count' => $this->statusChipsQuery()->unpaid()->count(), 'active' => $only(InvoiceStatus::Unpaid)],
            'paid' => ['label' => __('Paid'), 'count' => $this->statusChipsQuery()->where('status', InvoiceStatus::Paid)->count(), 'active' => $only(InvoiceStatus::Paid)],
            'cancelled' => ['label' => __('Cancelled'), 'count' => $this->statusChipsQuery()->where('status', InvoiceStatus::Cancelled)->count(), 'active' => $only(InvoiceStatus::Cancelled)],
            'overdue' => [
                'label' => __('Overdue'),
                'count' => $this->statusChipsQuery()->unpaid()->whereDate('due_date', '<', today())->count(),
                'active' => $overdue && blank($status),
            ],
        ];
    }

    /** Called by a chip in filament/services/quick-filters. */
    public function applyQuickFilter(string $key): void
    {
        if (! in_array($key, self::STATUS_CHIPS, true)) {
            return;
        }

        $this->getTableFiltersForm()->fill();
        $filters = $this->getTable()->hasDeferredFilters() ? $this->tableDeferredFilters : $this->tableFilters;

        $filters['status']['value'] = in_array($key, ['unpaid', 'paid', 'cancelled'], true) ? $key : null;
        $filters['overdue']['isActive'] = $key === 'overdue';

        $this->tableDeferredFilters = $filters;
        $this->tableFilters = $filters;
        $this->updatedTableFilters();
    }
}
