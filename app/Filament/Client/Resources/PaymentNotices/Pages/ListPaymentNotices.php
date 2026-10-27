<?php

namespace App\Filament\Client\Resources\PaymentNotices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListPaymentNotices extends ListRecords
{
    use HasStatusChips;

    protected static string $resource = PaymentNoticeResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->statusChipsComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /** All, Unpaid, Paid and Overdue, as over the admin's list (no Cancelled: the client doesn't see those). */
    protected function statusChipDefinitions(): array
    {
        $status = fn (?InvoiceStatus $status, bool $overdue = false) => ['status' => ['value' => $status?->value], 'overdue' => ['isActive' => $overdue]];

        return [
            'all' => ['label' => __('All notices'), 'filters' => $status(null), 'query' => fn (Builder $query) => $query],
            'unpaid' => ['label' => __('Unpaid'), 'filters' => $status(InvoiceStatus::Unpaid), 'query' => fn (Builder $query) => $query->unpaid(), 'dot' => 'warning'],
            'paid' => ['label' => __('Paid'), 'filters' => $status(InvoiceStatus::Paid), 'query' => fn (Builder $query) => $query->where('status', InvoiceStatus::Paid), 'dot' => 'success'],
            'overdue' => [
                'label' => __('Overdue'),
                'filters' => $status(null, overdue: true),
                'query' => fn (Builder $query) => $query->unpaid()->whereDate('due_date', '<', today()),
                'dot' => 'danger',
            ],
        ];
    }

    protected function chipsQuery(): Builder
    {
        return PaymentNoticeResource::getEloquentQuery();
    }
}
