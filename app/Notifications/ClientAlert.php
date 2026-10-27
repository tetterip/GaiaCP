<?php

namespace App\Notifications;

use Filament\Notifications\DatabaseNotification;

/**
 * A notification of the clients' bell (ClientAlerts). Its own class, so the `notifications` row's type tells the two
 * bells apart: the client area shows only these, /admin everything else (PanelNotifications).
 */
class ClientAlert extends DatabaseNotification {}
