<?php

namespace App\Filament\Resources\UpgradeRequests\Pages;

use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\UpgradeRequests\UpgradeRequestResource;
use App\Models\UpgradeRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Open requests first (the ones waiting for an answer), Completed for the ones approved or declined. */
class ListUpgradeRequests extends ListRecords
{
    protected static string $resource = UpgradeRequestResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open requests'))
                ->badge(UpgradeRequest::query()->pending()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeRequestStatus::Pending)),
            'completed' => Tab::make(__('Completed requests'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', ChangeRequestStatus::Pending)),
        ];
    }
}
