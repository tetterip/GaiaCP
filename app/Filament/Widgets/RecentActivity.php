<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\ActivityLog;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * What happened lately, from the Activity log: the last SHOWN payments, orders, new clients, cancellations and ticket
 * replies, newest first, with a button per kind to see only those. For admins who may open the Activity log.
 */
class RecentActivity extends Widget
{
    use FitsDashboard;

    public const SHOWN = 8;

    /** kind => the Activity log events it shows */
    public const KINDS = [
        'payments' => ['payment.recorded', 'payment.refunded', 'payment.reversed'],
        'orders' => ['order.placed', 'order.client_placed', 'order.completed'],
        'clients' => ['client.created', 'client.signed_up'],
        'cancellations' => ['service.cancellation_requested', 'service.cancellation_approved', 'service.terminated', 'service.auto_terminated'],
        'tickets' => ['ticket.client_replied', 'ticket.replied', 'ticket.closed'],
    ];

    private const ICONS = [
        'payments' => 'heroicon-o-banknotes',
        'orders' => 'heroicon-o-shopping-cart',
        'clients' => 'heroicon-o-user-plus',
        'cancellations' => 'heroicon-o-x-circle',
        'tickets' => 'heroicon-o-chat-bubble-left-right',
    ];

    private const TONES = ['payments' => 'ok', 'orders' => 'info', 'clients' => 'info', 'cancellations' => 'bad', 'tickets' => 'warn'];

    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    protected string $view = 'filament.dashboard.recent-activity';

    /** The kind shown; empty for all of them. */
    public string $kind = '';

    public static function dashboardLabel(): string
    {
        return __('Recent activity');
    }

    public static function canView(): bool
    {
        return ActivityLogResource::canAccess();
    }

    /** @return array<string, string> kind => its button */
    public static function kinds(): array
    {
        return [
            'payments' => __('Payments'),
            'orders' => __('Orders'),
            'clients' => __('Clients'),
            'cancellations' => __('Cancellations'),
            'tickets' => __('Tickets'),
        ];
    }

    public function show(string $kind): void
    {
        $this->kind = isset(self::KINDS[$kind]) ? $kind : '';
    }

    /**
     * @return list<array{icon: string, tone: string, title: string, sub: string, url: string}>
     */
    public function rows(): array
    {
        $events = isset(self::KINDS[$this->kind]) ? self::KINDS[$this->kind] : array_merge(...array_values(self::KINDS));
        $kindOf = collect(self::KINDS)->flatMap(fn (array $events, string $kind) => array_fill_keys($events, $kind));
        $clients = ClientResource::canAccess();
        $services = ServiceResource::canAccess();

        return ActivityLog::query()->whereIn('event', $events)->with(['client', 'service'])->latest('id')->limit(self::SHOWN)->get()
            ->map(function (ActivityLog $log) use ($kindOf, $clients, $services) {
                $kind = $kindOf[$log->event];
                $about = $log->client ? $log->client->name : ($log->service?->domain ?: $log->service?->label);

                return [
                    'icon' => self::ICONS[$kind],
                    'tone' => self::TONES[$kind],
                    'title' => Str::limit($log->description, 110),
                    'sub' => collect([$about, $log->created_at->diffForHumans()])->filter()->join(' · '),
                    'url' => match (true) {
                        $kind !== 'clients' && $log->service_id !== null && $services => ServiceResource::getUrl('edit', ['record' => $log->service_id]),
                        $log->client_id !== null && $clients => ClientResource::getUrl('edit', ['record' => $log->client_id]),
                        default => ActivityLogResource::getUrl(parameters: ['filters' => ['event' => ['value' => $log->event]]]),
                    },
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'url' => ActivityLogResource::getUrl(),
            'kinds' => self::kinds(),
            'rows' => $this->rows(),
        ];
    }
}
