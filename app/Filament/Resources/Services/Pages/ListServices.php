<?php

namespace App\Filament\Resources\Services\Pages;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Reports\Csv;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    /** Tab key => the service types it lists; the sidebar links to each one. */
    public const TABS = [
        'hosting' => [ServiceType::Hosting],
        'domains' => [ServiceType::Domain],
        'other' => [ServiceType::Maintenance, ServiceType::Other],
    ];

    /** The chips above the table: each sets the status / due soon filters, so they and the filter form agree. */
    public const QUICK_FILTERS = ['all', 'active', 'suspended', 'pending', 'due_soon'];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->csv()),
            CreateAction::make()
                ->url(fn () => ServiceResource::getUrl('create', array_filter(['type' => self::TABS[$this->activeTab][0]->value ?? null]))),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (array $types) => Service::query()->whereIn('type', $types)->whereIn('status', ServicesTable::OPEN)->count();

        return [
            'all' => Tab::make(__('All')),
            'hosting' => Tab::make(__('Hosting'))->badge($count(self::TABS['hosting']))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', self::TABS['hosting'])),
            'domains' => Tab::make(__('Domains'))->badge($count(self::TABS['domains']))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', self::TABS['domains'])),
            'other' => Tab::make(__('Other services'))->badge($count(self::TABS['other']))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', self::TABS['other'])),
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return match ($this->activeTab) {
            'hosting' => __('Hosting'),
            'domains' => __('Domains'),
            'other' => __('Other services'),
            default => __('All services'),
        };
    }

    public function getSubheading(): ?string
    {
        return match ($this->activeTab) {
            'hosting' => __('The hosting accounts of all clients, on every server'),
            'domains' => __('Every client\'s domains, with their expiry at the registrar'),
            'other' => __('Maintenance and the other services you bill for'),
            default => __('Every service of every client'),
        };
    }

    /** "Services" over the title, as the menu groups them. */
    public function getBreadcrumbs(): array
    {
        return [ServiceResource::getUrl('index', ['tab' => $this->activeTab]) => __('Services')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament.services.quick-filters')
                    ->viewData(fn () => ['filters' => $this->quickFilters()]),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /** @return array<string, array{label: string, count: int, active: bool}> */
    public function quickFilters(): array
    {
        $tab = $this->getCachedTabs()[$this->activeTab ?? 'all'] ?? null;
        $query = fn () => $tab ? $tab->modifyQuery(Service::query()) : Service::query();
        $statuses = collect($this->tableFilters['status']['values'] ?? [])->sort()->values()->all();
        $dueSoon = (bool) ($this->tableFilters['due_soon']['isActive'] ?? false);
        $only = fn (ServiceStatus $status) => ! $dueSoon && $statuses === [$status->value];
        $open = collect(ServicesTable::OPEN)->map->value->sort()->values()->all();

        return [
            'all' => ['label' => __('All'), 'count' => $query()->whereIn('status', ServicesTable::OPEN)->count(), 'active' => ! $dueSoon && $statuses === $open],
            'active' => ['label' => __('Active'), 'count' => $query()->where('status', ServiceStatus::Active)->count(), 'active' => $only(ServiceStatus::Active)],
            'suspended' => ['label' => __('Suspended'), 'count' => $query()->where('status', ServiceStatus::Suspended)->count(), 'active' => $only(ServiceStatus::Suspended)],
            'pending' => ['label' => __('Pending'), 'count' => $query()->where('status', ServiceStatus::Pending)->count(), 'active' => $only(ServiceStatus::Pending)],
            'due_soon' => [
                'label' => __('Due in :count days', ['count' => ServicesTable::DUE_SOON_DAYS]),
                'count' => $query()->billable()->whereDate('next_due_date', '<=', today()->addDays(ServicesTable::DUE_SOON_DAYS))->count(),
                'active' => $dueSoon && $statuses === $open,
            ],
        ];
    }

    public function applyQuickFilter(string $key): void
    {
        if (! in_array($key, self::QUICK_FILTERS, true)) {
            return;
        }

        // Back to the filters' defaults, then the one the chip names
        $this->getTableFiltersForm()->fill();
        $filters = $this->getTable()->hasDeferredFilters() ? $this->tableDeferredFilters : $this->tableFilters;

        if (in_array($key, ['active', 'suspended', 'pending'], true)) {
            $filters['status']['values'] = [$key];
        }

        $filters['due_soon']['isActive'] = $key === 'due_soon';

        $this->tableDeferredFilters = $filters;
        $this->tableFilters = $filters;
        $this->updatedTableFilters();
    }

    /** The rows that match the tab, filters and search, in the table's order. */
    public function csv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()->with(['client', 'product', 'server']);

        return Csv::download(
            'services-'.($this->activeTab ?? 'all').'-'.today()->format('Y-m-d').'.csv',
            [__('Service'), __('Label'), __('Type'), __('Client'), __('Company'), __('Product'), __('Server'), __('Username'),
                __('Price'), __('Currency'), __('Billing cycle'), __('Next due'), __('Status')],
            (function () use ($query) {
                foreach ($query->lazy(200) as $service) {
                    yield [
                        $service->domain, $service->label, $service->type->getLabel(), $service->client->name, $service->client->company,
                        $service->product?->name, $service->server?->name, $service->username,
                        Money::toDecimal($service->price_cents), $service->currency, $service->billing_cycle->getLabel(),
                        $service->next_due_date?->format(AppServiceProvider::dateFormat()), $service->status->getLabel(),
                    ];
                }
            })(),
        );
    }
}
