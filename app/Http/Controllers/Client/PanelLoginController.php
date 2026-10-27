<?php

namespace App\Http\Controllers\Client;

use App\Actions\ServiceLifecycle;
use App\Filament\Client\Actions\HostingSelfService;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client area's "Log in to cPanel / Plesk" and its Quick shortcuts (?to=): a fresh one-time panel session on each
 * click, so the link on the page carries nothing secret. Only the signed-in client's own services (ServiceResource's scope: another's is a 404).
 */
class PanelLoginController extends Controller
{
    public function __invoke(Request $request, int $service, ServiceLifecycle $lifecycle): RedirectResponse
    {
        /** @var Service $record */
        $record = ServiceResource::getEloquentQuery()->findOrFail($service);

        abort_unless(HostingSelfService::canLogIn($record), 404);

        // ?to=: one of the panel's Quick shortcuts, else none
        $to = $request->query('to');
        $shortcut = filled($to) ? $to : null;
        abort_if($shortcut !== null && (! is_string($shortcut) || ! array_key_exists($shortcut, HostingSelfService::shortcuts($record))), 404);

        try {
            return redirect()->away($lifecycle->loginUrl($record, (string) $request->ip(), $shortcut));
        } catch (ProvisioningException) {
            Notification::make()
                ->title(__('We could not open the control panel.'))
                ->body(__('Please try again later, or contact us.'))
                ->danger()
                ->persistent()
                ->send();

            return redirect(ViewService::getUrl(['record' => $record]));
        }
    }
}
