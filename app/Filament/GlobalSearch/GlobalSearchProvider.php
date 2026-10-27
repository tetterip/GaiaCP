<?php

namespace App\Filament\GlobalSearch;

use App\Enums\ServiceType;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider as Contract;
use Illuminate\Support\Collection;

/**
 * Filament's default global search, except services are split into "Products/Services" and
 * "Domains" (like WHMCS) and every group header carries its result count.
 */
class GlobalSearchProvider implements Contract
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $builder = GlobalSearchResults::make();

        $resources = Filament::getResources();

        usort(
            $resources,
            fn (string $a, string $b): int => ($a::getGlobalSearchSort() ?? 0) <=> ($b::getGlobalSearchSort() ?? 0),
        );

        foreach ($resources as $resource) {
            if (! $resource::canGloballySearch()) {
                continue;
            }

            if ($resource === ServiceResource::class) {
                [$domains, $services] = ServiceResource::getGlobalSearchRecords($query)
                    ->partition(fn (Service $service): bool => $service->type === ServiceType::Domain);

                $this->category($builder, 'Products/Services', $services->map(ServiceResource::toGlobalSearchResult(...))->filter());
                $this->category($builder, __('Domains'), $domains->map(ServiceResource::toGlobalSearchResult(...))->filter());

                continue;
            }

            $this->category($builder, $resource::getPluralModelLabel(), $resource::getGlobalSearchResults($query));
        }

        return $builder;
    }

    private function category(GlobalSearchResults $builder, string $name, Collection $results): void
    {
        if ($results->isNotEmpty()) {
            $builder->category(ucfirst($name)." ({$results->count()})", $results->values());
        }
    }
}
