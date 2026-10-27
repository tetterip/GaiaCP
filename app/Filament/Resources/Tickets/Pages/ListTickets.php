<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Waiting for us first, then the admin's own, waiting for the client, on hold, closed and all. */
class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open a ticket'),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (?TicketStatus $status) => Ticket::query()->where('status', $status)->count() ?: null;

        return [
            'waiting' => Tab::make(__('Waiting for us'))
                ->badge($count(TicketStatus::Open))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Open)),
            'mine' => Tab::make(__('Assigned to me'))
                ->badge(Ticket::query()->active()->where('assigned_to', auth()->id())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->active()->where('assigned_to', auth()->id())),
            'answered' => Tab::make(__('Waiting for the client'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Answered)),
            'on_hold' => Tab::make(__('On hold'))
                ->badge($count(TicketStatus::OnHold))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::OnHold)),
            'closed' => Tab::make(__('Closed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TicketStatus::Closed)),
            'all' => Tab::make(__('All')),
        ];
    }
}
