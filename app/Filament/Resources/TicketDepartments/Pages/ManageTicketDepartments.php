<?php

namespace App\Filament\Resources\TicketDepartments\Pages;

use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketDepartments extends ManageRecords
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add department'),
        ];
    }
}
