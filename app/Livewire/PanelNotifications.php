<?php

namespace App\Livewire;

use App\Notifications\ClientAlert;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The bell of both panels: the client area lists only the clients' notifications (ClientAlerts), /admin only the
 * others, so an admin linked to a client sees each in its own panel.
 */
class PanelNotifications extends DatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('type', Filament::getId() === 'client' ? '=' : '!=', ClientAlert::class);
    }
}
