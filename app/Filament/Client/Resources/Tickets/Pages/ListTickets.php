<?php

namespace App\Filament\Client\Resources\Tickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Client\Concerns\HasStatusChips;
use App\Filament\Client\Resources\Tickets\TicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    use HasStatusChips;

    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open a ticket'),
        ];
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
        return $this->chipsPerStatus(__('All tickets'), TicketStatus::cases());
    }

    protected function chipsQuery(): Builder
    {
        return TicketResource::getEloquentQuery();
    }
}
