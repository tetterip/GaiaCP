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
 * The client area's "Log in to WordPress": a fresh one-time WP Toolkit login on each click, for one of the account's own
 * sites (ServiceLifecycle reads the list again and refuses another's). Only the signed-in client's own services
 * (ServiceResource's scope: another's is a 404).
 */
class WordPressLoginController extends Controller
{
    public function __invoke(Request $request, int $service, string $site, ServiceLifecycle $lifecycle): RedirectResponse
    {
        /** @var Service $record */
        $record = ServiceResource::getEloquentQuery()->findOrFail($service);

        abort_unless(HostingSelfService::canUseWordPress($record), 404);

        try {
            return redirect()->away($lifecycle->wordPressLoginUrl($record, $site, (string) $request->ip()));
        } catch (ProvisioningException) {
            Notification::make()
                ->title(__('We could not open WordPress.'))
                ->body(__('Please try again later, or contact us.'))
                ->danger()
                ->persistent()
                ->send();

            return redirect(ViewService::getUrl(['record' => $record]));
        }
    }
}
