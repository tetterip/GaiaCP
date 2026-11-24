<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ServiceLifecycle;
use App\Filament\Resources\Services\ServiceResource;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A service's Account → "Log in to cPanel / Plesk …": the client's account on the panel, in a new tab, as the client
 * area's button opens it. A one-time session on each click, so the link on the page carries nothing secret. Only
 * admins who can open Services.
 */
class ServicePanelLoginController extends Controller
{
    public function __invoke(Request $request, Service $service, ServiceLifecycle $lifecycle): RedirectResponse
    {
        abort_unless(ServiceResource::canAccess(), 403);

        try {
            return redirect()->away($lifecycle->loginUrl($service, (string) $request->ip()));
        } catch (ProvisioningException $e) {
            Notification::make()
                ->title(__('Could not open the control panel'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return redirect(ServiceResource::getUrl('edit', ['record' => $service]));
        }
    }
}
