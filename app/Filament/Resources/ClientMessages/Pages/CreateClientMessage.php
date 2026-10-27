<?php

namespace App\Filament\Resources\ClientMessages\Pages;

use App\Filament\Resources\ClientMessages\ClientMessageResource;
use Filament\Resources\Pages\CreateRecord;

/** Saved as a draft: the email goes out only with Send on the next page. */
class CreateClientMessage extends CreateRecord
{
    protected static string $resource = ClientMessageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return ClientMessageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
