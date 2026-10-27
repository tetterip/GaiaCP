<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Actions\TestConnectionAction;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestConnectionAction::make(),
            CompareServer::action(),
            DeleteAction::make()
                ->hidden(fn (Server $record) => $record->services()->exists()),
        ];
    }
}
