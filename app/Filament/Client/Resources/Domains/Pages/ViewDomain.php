<?php

namespace App\Filament\Client\Resources\Domains\Pages;

use App\Filament\Client\Actions\DomainSelfService;
use App\Filament\Client\Resources\Domains\DomainResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return DomainSelfService::actions();
    }

    /** Its name, without Filament's "View" in front. */
    public function getTitle(): string
    {
        return $this->getRecordTitle();
    }
}
