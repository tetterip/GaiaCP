<?php

namespace App\Http\Controllers\Client;

use App\Actions\ClientAreaAccess;
use App\Filament\Resources\Clients\ClientResource;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/** "Back to admin" on the bar an admin sees while looking at the client area as a client (ClientAreaAccess::logInAs()). */
class StopViewingController extends Controller
{
    public function __invoke(ClientAreaAccess $access): RedirectResponse
    {
        abort_unless(ClientAreaAccess::viewing(), 404);

        $client = $access->stopViewing();

        return redirect($client
            ? ClientResource::getUrl('edit', ['record' => $client], panel: 'admin')
            : Filament::getPanel('admin')->getUrl());
    }
}
