<?php

namespace App\Filament\Resources\CannedReplies\Pages;

use App\Filament\Resources\CannedReplies\CannedReplyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCannedReplies extends ManageRecords
{
    protected static string $resource = CannedReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add canned reply'),
        ];
    }
}
