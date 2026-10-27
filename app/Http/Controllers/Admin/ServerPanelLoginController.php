<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ServerPanelLogin;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\ServerResource;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Provisioning\ProvisioningException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Servers list's "Log in to control panel": a one-time admin session on each click, so the link on the page
 * carries nothing secret. Only admins who can open Servers (super admins).
 */
class ServerPanelLoginController extends Controller
{
    public function __invoke(Request $request, Server $server, ServerPanelLogin $login): RedirectResponse
    {
        abort_unless(ServerResource::canAccess(), 403);

        try {
            return redirect()->away($login->url($server, (string) $request->ip()));
        } catch (ProvisioningException $e) {
            Notification::make()
                ->title(__('Could not open the control panel'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return redirect(ListServers::getUrl());
        }
    }
}
