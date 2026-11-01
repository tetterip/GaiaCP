<?php

namespace App\Filament\Resources\CustomPages\Pages;

use App\Filament\Resources\CustomPages\CustomPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomPage extends CreateRecord
{
    protected static string $resource = CustomPageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
