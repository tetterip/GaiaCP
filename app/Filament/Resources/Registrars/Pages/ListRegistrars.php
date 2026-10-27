<?php

namespace App\Filament\Resources\Registrars\Pages;

use App\Filament\Resources\Registrars\RegistrarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistrars extends ListRecords
{
    protected static string $resource = RegistrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
