<?php

namespace App\Filament\Resources\ClientMessages\Pages;

use App\Filament\Resources\ClientMessages\ClientMessageActions;
use App\Filament\Resources\ClientMessages\ClientMessageResource;
use App\Models\ClientMessage;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientMessage extends EditRecord
{
    protected static string $resource = ClientMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ClientMessageActions::send(),
            ClientMessageActions::test(),
            // Once emailed it is history: stop showing it in the client area instead
            DeleteAction::make()->hidden(fn (ClientMessage $record) => $record->isQueued()),
        ];
    }
}
