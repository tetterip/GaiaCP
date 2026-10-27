<?php

namespace App\Support;

use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Client\Resources\Domains\DomainResource;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Filament\Client\Resources\Services\ServiceResource;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use Filament\Facades\Filament;

/**
 * The "View in the client area" link of a client email (Settings → Client area → Links in emails): the payment
 * notice, service or support ticket the email is about, or Home. None for a client who can't log in, and never to a service the
 * client area doesn't show (hidden or cancelled): Home instead.
 */
class ClientAreaLinks
{
    public static function for(Client $client, Invoice|Service|Ticket|null $record = null): ?string
    {
        if (! config('gaiacp.client_area.email_links') || ! $client->exists || ! $client->user?->canAccessPanel(Filament::getPanel('client'))) {
            return null;
        }

        return match (true) {
            $record instanceof Invoice && $record->client_id === $client->id => PaymentNoticeResource::getUrl('view', ['record' => $record], panel: 'client'),
            $record instanceof Service && self::shows($client, $record) => $record->type === ServiceType::Domain
                ? DomainResource::getUrl('view', ['record' => $record], panel: 'client')
                : ServiceResource::getUrl('view', ['record' => $record], panel: 'client'),
            $record instanceof Ticket && $record->client_id === $client->id && config('gaiacp.support.client_tickets') => TicketResource::getUrl('view', ['record' => $record], panel: 'client'),
            default => Dashboard::getUrl(panel: 'client'),
        };
    }

    private static function shows(Client $client, Service $service): bool
    {
        return $service->client_id === $client->id && $service->client_visible && $service->status !== ServiceStatus::Cancelled;
    }
}
