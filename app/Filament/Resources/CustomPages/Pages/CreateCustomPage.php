<?php

namespace App\Filament\Resources\CustomPages\Pages;

use App\Filament\Resources\CustomPages\CustomPageResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateCustomPage extends CreateRecord
{
    protected static string $resource = CustomPageResource::class;

    public function getSubheading(): Htmlable
    {
        return CustomPageResource::menusHint();
    }

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
