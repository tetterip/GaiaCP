<?php

namespace App\Filament\Resources\Tlds\Pages;

use App\Filament\Resources\Registrars\RegistrarResource;
use App\Filament\Resources\Tlds\TldResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageTlds extends ManageRecords
{
    protected static string $resource = TldResource::class;

    public function getSubheading(): ?string
    {
        return __('Selling prices per TLD. New orders offer only the terms with a price, and pick the TLD\'s preferred registrar (the admin can choose another on the order).');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrars')
                ->label('Registrars')
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('gray')
                ->url(RegistrarResource::getUrl())
                ->visible(RegistrarResource::canAccess()),
            CreateAction::make()
                ->label('Add TLD')
                ->modalWidth('3xl'),
        ];
    }
}
