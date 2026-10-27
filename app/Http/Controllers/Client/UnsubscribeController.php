<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "Stop these emails" link at the end of an informational message to clients (signed, no login): a page with a
 * button, so a mail scanner opening the link changes nothing; pressing it turns off the client's informational emails.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, Client $client): Response
    {
        app()->setLocale($client->preferredLocale());

        return response()->view('client-area.unsubscribe', ['client' => $client, 'done' => ! $client->informational_emails, 'action' => $request->fullUrl()]);
    }

    public function store(Request $request, Client $client): Response
    {
        app()->setLocale($client->preferredLocale());

        if ($client->informational_emails) {
            $client->update(['informational_emails' => false]);
            ActivityLog::record('client.unsubscribed', "{$client->email} turned off informational emails", client: $client);
        }

        return response()->view('client-area.unsubscribe', ['client' => $client, 'done' => true, 'action' => null]);
    }
}
