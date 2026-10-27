<?php

namespace App\Filament\Resources\ClientFields\Pages;

use App\Filament\Resources\ClientFields\ClientFieldResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClientFields extends ManageRecords
{
    protected static string $resource = ClientFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add field')->mutateDataUsing(fn (array $data) => ClientFieldResource::forStorage($data)),
        ];
    }
}
