<?php

namespace App\Filament\Resources\CustomPages\Pages;

use App\Filament\Resources\CustomPages\CustomPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomPage extends CreateRecord
{
    protected static string $resource = CustomPageResource::class;

    // A page is opened for editing once saved, so "Create & create another" has no use here
    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
