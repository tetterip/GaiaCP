<?php

namespace App\Filament\Client\Resources\Services\Pages;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Services\ServiceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListServices extends ListRecords
{
    use HasStatusChips;

    protected static string $resource = ServiceResource::class;

    /** hosting or other: the sidebar's Hosting and Other services; empty lists every service but domains. */
    #[Url]
    public ?string $kind = null;

    public function getTitle(): string|Htmlable
    {
        return match ($this->kind) {
            'hosting' => __('Hosting'),
            'other' => __('Other services'),
            default => parent::getTitle(),
        };
    }

    protected function getHeaderActions(): array
    {
        // With nothing listed yet, the empty list's own button says it
        return [Order::callToAction()->hidden(fn () => ! $this->chipsQuery()->exists())];
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
        return ServiceResource::getEloquentQuery()
            ->when($this->kind === 'hosting', fn (Builder $query) => $query->where('type', ServiceType::Hosting))
            ->when($this->kind === 'other', fn (Builder $query) => $query->where('type', '!=', ServiceType::Hosting));
    }
}
