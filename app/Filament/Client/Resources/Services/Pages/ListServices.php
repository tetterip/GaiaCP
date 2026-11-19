<?php

namespace App\Filament\Client\Resources\Services\Pages;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Pages\Order;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Livewire\ProductPicker;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
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
        return [
            // New hosting / New service: the products of this kind in a window over the list (ProductPicker)
            Action::make('newService')
                ->label($this->kind === 'hosting' ? __('New hosting') : __('Order a new service'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading($this->kind === 'hosting' ? __('New hosting') : __('Order a new service'))
                ->modalDescription(__('Choose a plan; you place the order from the cart.'))
                ->modalContent(fn () => view('filament.client.product-picker-modal', ['kind' => $this->kind === 'hosting' ? 'hosting' : 'other']))
                ->modalWidth(Width::SixExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->visible(fn () => Order::canAccess() && ProductPicker::offersAnything($this->kind === 'hosting' ? 'hosting' : 'other')),
            // Nothing to pick from here (no product of this kind): the Order page, as before. With nothing listed yet, the empty list's own button says it
            Order::callToAction()->hidden(fn () => ! $this->chipsQuery()->exists() || ProductPicker::offersAnything($this->kind === 'hosting' ? 'hosting' : 'other')),
        ];
    }

    /** A product went in the cart from the window: close it and show the cart from the side. */
    #[On('product-added')]
    public function productAdded(): void
    {
        $this->unmountAction();
        $this->dispatch('open-cart');
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

    /** All and one per status, then one per kind of service (SSL, Maintenance…) while Other services holds two or more. */
    protected function statusChipDefinitions(): array
    {
        $none = ['status' => ['value' => null], 'type' => ['value' => null]];
        $chips = [];

        foreach ($this->chipsPerStatus(__('All'), [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending, ServiceStatus::Terminated]) as $key => $chip) {
            $chips[$key] = ['filters' => array_replace($none, $chip['filters'])] + $chip;
        }

        if ($this->kind === 'hosting') {
            return $chips;
        }

        $types = $this->chipsQuery()->distinct()->pluck('type')
            ->map(fn ($type) => $type instanceof ServiceType ? $type : ServiceType::from($type))
            ->sortBy(fn (ServiceType $type) => $type->getLabel())
            ->values();

        if ($types->count() > 1) {
            foreach ($types as $type) {
                $chips['type-'.$type->value] = [
                    'label' => $type->getLabel(),
                    'filters' => array_replace($none, ['type' => ['value' => $type->value]]),
                    'query' => fn (Builder $query) => $query->where('type', $type),
                ];
            }
        }

        return $chips;
    }

    protected function chipsQuery(): Builder
    {
        return ServiceResource::getEloquentQuery()
            ->when($this->kind === 'hosting', fn (Builder $query) => $query->where('type', ServiceType::Hosting))
            ->when($this->kind === 'other', fn (Builder $query) => $query->where('type', '!=', ServiceType::Hosting));
    }
}
