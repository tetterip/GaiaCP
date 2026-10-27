<?php

namespace App\Filament\Actions;

use App\Models\Server;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class TestConnectionAction
{
    public static function make(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (Server $record) {
                try {
                    $provisioner = app(ProvisionerFactory::class)->forServer($record);
                    $version = $provisioner->testConnection();
                    $packages = $provisioner->packages();

                    Notification::make()
                        ->title(__('Connected: :version', ['version' => $version]))
                        ->body(__(':count package(s): :list', ['count' => count($packages), 'list' => implode(', ', $packages) ?: __('none')]))
                        ->success()
                        ->send();
                } catch (ProvisioningException $e) {
                    Notification::make()
                        ->title(__('Connection failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
