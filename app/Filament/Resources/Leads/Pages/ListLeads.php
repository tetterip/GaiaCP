<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** The open leads first, those to contact today, the admin's own, then won, lost and all. */
class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New lead'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))
                ->badge(Lead::query()->open()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'to_contact' => Tab::make(__('To contact'))
                ->badge(Lead::query()->toContact()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->toContact()),
            'mine' => Tab::make(__('Assigned to me'))
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('assigned_to', auth()->id())),
            'won' => Tab::make(__('Won'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeadStatus::Won)),
            'lost' => Tab::make(__('Lost'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeadStatus::Lost)),
            'all' => Tab::make(__('All')),
        ];
    }
}
