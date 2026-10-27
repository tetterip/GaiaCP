<?php

namespace App\Filament\Client\Resources\Services\Pages;

use App\Filament\Client\Actions\CertificateSelfService;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Actions\OrderAddon;
use App\Filament\Client\Actions\RequestCancellation;
use App\Filament\Client\Actions\RequestUpgrade;
use App\Filament\Client\Resources\Services\ServiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewService extends ViewRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [...CertificateSelfService::actions(), ...HostingSelfService::actions(), ...RequestUpgrade::actions(), ...OrderAddon::actions(), ...RequestCancellation::actions()];
    }

    /** Its name, without Filament's "View" in front. */
    public function getTitle(): string
    {
        return $this->getRecordTitle();
    }
}
