<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Widgets\Concerns\FitsDashboard;
use App\Models\Service;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Size;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The services still billed that are overdue or due within DAYS days: the SHOWN most urgent as a compact list (past
 * due, this week, later), each opening its service and with Record payment, and a link to the services list for the rest.
 */
class UpcomingRenewals extends Widget implements HasActions, HasSchemas
{
    use FitsDashboard;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const DAYS = 30;

    public const SHOWN = 7;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected string $view = 'filament.dashboard.upcoming-renewals';

    public static function dashboardLabel(): string
    {
        return __('Overdue and due in the next 30 days');
    }

    public static function canView(): bool
    {
        return ServiceResource::canAccess();
    }

    /** Record payment for the row's service, sent as the `service` argument; only one on this card can be paid. */
    public function recordPaymentAction(): Action
    {
        return RecordPaymentAction::make()
            ->record(fn (array $arguments): ?Service => self::query()->find($arguments['service'] ?? null))
            ->iconButton()
            ->size(Size::Small)
            ->after(fn () => $this->dispatch('$refresh'));
    }

    /** @return Builder<Service> */
    public static function query(): Builder
    {
        return Service::query()->billable()->whereOnDate('next_due_date', '<=', today()->addDays(self::DAYS));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $week = today()->addDays(7);
        $rows = RecordPaymentAction::withChecks(self::query())->with('client')
            ->orderBy('next_due_date')->orderBy('id')->limit(self::SHOWN)->get();

        return [
            'url' => ServiceResource::getUrl(),
            'total' => self::query()->count(),
            'labels' => ['overdue' => __('Past due'), 'week' => __('In 7 days'), 'later' => __('Later')],
            'groups' => $rows
                ->groupBy(fn (Service $service) => $service->isOverdue() ? 'overdue' : ($service->next_due_date->lte($week) ? 'week' : 'later'))
                ->map(fn ($group) => $group->map(fn (Service $service) => [
                    'id' => $service->id,
                    'title' => $service->domain ?: $service->label,
                    'sub' => collect([$service->client?->name, $service->type->getLabel()])->filter()->join(' · '),
                    'date' => $service->next_due_date->format(AppServiceProvider::dateFormat()),
                    'amount' => Money::format($service->price_cents, $service->currency),
                    'url' => ServiceResource::getUrl('edit', ['record' => $service]),
                    'payable' => RecordPaymentAction::payable($service),
                ])->all())
                ->all(),
        ];
    }
}
