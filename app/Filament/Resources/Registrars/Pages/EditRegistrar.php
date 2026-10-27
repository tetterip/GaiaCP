<?php

namespace App\Filament\Resources\Registrars\Pages;

use App\Filament\Actions\CheckDomainAction;
use App\Filament\Resources\Registrars\RegistrarResource;
use App\Models\Registrar;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegistrar extends EditRecord
{
    protected static string $resource = RegistrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CheckDomainAction::make()->hidden(fn (Registrar $record) => $record->isManual()),
            DeleteAction::make(),
        ];
    }

    /**
     * credentials is hidden from the model's arrays, so the form would open with every login field empty.
     * Secret fields (the password) clear their own state with formatStateUsing(), so they never reach the page.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Registrar $record */
        $record = $this->getRecord();

        $data['credentials'] = $record->credentials ?? [];

        return $data;
    }

    /** A blank login field (the password, which is never sent to the page) keeps the stored one. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Registrar $record */
        $record = $this->getRecord();

        foreach ($data['credentials'] ?? [] as $field => $value) {
            if (blank($value)) {
                $data['credentials'][$field] = $record->credentials[$field] ?? null;
            }
        }

        return $data;
    }
}
