<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Actions\Leads;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(Leads::class)->create($data);
    }
}
