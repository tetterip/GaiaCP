<?php

namespace App\Filament\Resources\ClientMessages\Pages;

use App\Filament\Resources\ClientMessages\ClientMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientMessages extends ListRecords
{
    protected static string $resource = ClientMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
