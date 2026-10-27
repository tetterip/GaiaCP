<?php

namespace App\Listeners;

use App\ClientSessions\ClientSessions;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Logout;

/** Client area sign-in history: failed tries with a login's email, and the row of a browser that signs out. */
class TrackClientSignIns
{
    public function __construct(private ClientSessions $sessions) {}

    public function handleFailed(Failed $event): void
    {
        if ($event->guard === 'client' && $event->user instanceof User && $event->user->client_id !== null) {
            $this->sessions->failed($event->user, request());
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard === 'client') {
            $this->sessions->ended(request());
        }
    }
}
