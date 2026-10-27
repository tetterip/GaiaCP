<?php

namespace App\Filament\Resources\CancellationRequests\Pages;

use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\CancellationRequests\CancellationRequestResource;
use App\Models\CancellationRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Open requests first (the ones waiting for an answer), Completed for the ones approved or declined. */
class ListCancellationRequests extends ListRecords
{
    protected static string $resource = CancellationRequestResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open requests'))
                ->badge(CancellationRequest::query()->pending()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ChangeRequestStatus::Pending)),
            'completed' => Tab::make(__('Completed requests'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', ChangeRequestStatus::Pending)),
        ];
    }
}
