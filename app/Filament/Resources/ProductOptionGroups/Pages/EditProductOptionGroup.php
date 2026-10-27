<?php

namespace App\Filament\Resources\ProductOptionGroups\Pages;

use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductOptionGroup extends EditRecord
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
