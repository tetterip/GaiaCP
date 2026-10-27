<?php

namespace App\Filament\Client\Resources\Domains\Pages;

use App\Enums\ServiceStatus;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Domains\DomainResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListDomains extends ListRecords
{
    use HasStatusChips;

    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        // With nothing listed yet, the empty list's own button says it
        return [Order::callToAction(domain: true)->hidden(fn () => ! $this->chipsQuery()->exists())];
    }

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

    protected function statusChipDefinitions(): array
    {
        return $this->chipsPerStatus(__('All'), [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending, ServiceStatus::Terminated]);
    }

    protected function chipsQuery(): Builder
    {
        return DomainResource::getEloquentQuery();
    }
}
