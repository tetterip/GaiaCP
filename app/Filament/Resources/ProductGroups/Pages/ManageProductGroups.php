<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProductGroups extends ManageRecords
{
    protected static string $resource = ProductGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add group')->modalWidth('3xl'),
        ];
    }
}
