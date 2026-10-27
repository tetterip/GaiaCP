<?php

namespace App\Filament\Widgets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Ticket;
use Filament\Widgets\Widget;

/** Under the renewals table: how many tickets wait for us, for the client and on hold, and the oldest ones waiting for us. */
class SupportTickets extends Widget
{
    use FitsDashboard;

    public const SHOWN = 5;

    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected string $view = 'filament.dashboard.support-tickets';

    public static function dashboardLabel(): string
    {
        return __('Support');
    }

    public static function canView(): bool
    {
        return TicketResource::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $count = fn (TicketStatus $status) => Ticket::query()->where('status', $status)->count();

        return [
            'url' => TicketResource::getUrl(),
            'counts' => [
                ['label' => __('Waiting for us'), 'count' => $count(TicketStatus::Open), 'tone' => 'warn', 'tab' => 'waiting'],
                ['label' => __('Waiting for the client'), 'count' => $count(TicketStatus::Answered), 'tone' => 'info', 'tab' => 'answered'],
                ['label' => __('On hold'), 'count' => $count(TicketStatus::OnHold), 'tone' => 'muted', 'tab' => 'on_hold'],
            ],
            'tickets' => Ticket::query()->waiting()->with('client')->orderBy('last_reply_at')->limit(self::SHOWN)->get()
                ->map(fn (Ticket $ticket) => [
                    'title' => "{$ticket->number} · {$ticket->subject}",
                    'sub' => trim(($ticket->requesterName() !== '' ? $ticket->requesterName().' · ' : '').__('waiting :time', ['time' => $ticket->last_reply_at?->diffForHumans(syntax: true)])),
                    'tone' => $ticket->priority === TicketPriority::High ? 'bad' : 'warn',
                    'url' => TicketResource::getUrl('view', ['record' => $ticket]),
                ])
                ->all(),
        ];
    }
}
