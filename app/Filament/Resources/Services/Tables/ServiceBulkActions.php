<?php

namespace App\Filament\Resources\Services\Tables;

use App\Actions\DomainLifecycle;
use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Actions\BulkOutcome;
use App\Filament\Actions\ChangePriceAction;
use App\Filament\Actions\MessageClientsBulkAction;
use App\Models\Service;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mass actions on the services picked in a list. Each does what the service page's own action does, service by
 * service (same panel and registrar calls, emails and activity log); the ones it can't apply to are named.
 */
class ServiceBulkActions
{
    /** Most services one action takes: each suspend, unsuspend or sync calls a server panel or a registrar. */
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([
            ChangePriceAction::bulk(),
            self::suspend(),
            self::unsuspend(),
            MessageClientsBulkAction::make(fn (Collection $records) => $records->pluck('client_id')->all()),
            self::endOfCycle(true),
            self::endOfCycle(false),
            self::syncDomains(),
        ]);
    }

    /** As Account → Suspend on each active one, with one reason for all. */
    public static function suspend(): BulkAction
    {
        $skip = fn (Service $service) => $service->status === ServiceStatus::Active ? null : __('not active');

        return BulkAction::make('suspend')
            ->label('Suspend')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->modalHeading(__('Suspend services'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('A hosting account is suspended on its server too, and the client gets the suspension email. One that fails stays active and is named below.'))
            ->schema([
                Select::make('reason_type')
                    ->label('Reason')
                    ->options([Service::SUSPENDED_OVERDUE => __('Overdue payment (a payment unsuspends it)'), 'other' => __('Other')])
                    ->default(Service::SUSPENDED_OVERDUE)
                    ->required()
                    ->live(),
                TextInput::make('reason')
                    ->label('Describe the reason')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get) => $get('reason_type') === 'other'),
            ])
            ->modalSubmitActionLabel(__('Suspend them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => BulkOutcome::run(
                $records->load('server'),
                $skip,
                fn (Service $service) => app(ServiceLifecycle::class)->suspend(
                    $service,
                    $data['reason_type'] === Service::SUSPENDED_OVERDUE ? Service::SUSPENDED_OVERDUE : $data['reason'],
                ),
                fn (Service $service) => $service->label,
            )->notify(':count service suspended|:count services suspended'));
    }

    /** As Account → Unsuspend on each suspended one. */
    public static function unsuspend(): BulkAction
    {
        $skip = fn (Service $service) => $service->status === ServiceStatus::Suspended ? null : __('not suspended');

        return BulkAction::make('unsuspend')
            ->label('Unsuspend')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Unsuspend services'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('A hosting account is unsuspended on its server too. One that fails stays suspended and is named below.'))
            ->modalSubmitActionLabel(__('Unsuspend them'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records->load('server'),
                $skip,
                fn (Service $service) => app(ServiceLifecycle::class)->unsuspend($service),
                fn (Service $service) => $service->label,
            )->notify(':count service unsuspended|:count services unsuspended'));
    }

    /**
     * Sets "Auto-terminate at the end of the cycle" (for a domain, Auto renew off) on each billable one, or takes it
     * off, as the service form's switch does.
     */
    public static function endOfCycle(bool $end): BulkAction
    {
        $skip = fn (Service $service) => match (true) {
            ! in_array($service->status, ServiceStatus::billable(), true) => __('not billed any more'),
            $service->auto_terminate === $end => $end ? __('already ends at the end of the cycle') : __('already renews'),
            default => null,
        };

        return BulkAction::make($end ? 'endAtCycleEnd' : 'keepRenewing')
            ->label($end ? __('End at the end of the cycle') : __('Keep renewing'))
            ->icon($end ? Heroicon::OutlinedCalendarDays : Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading($end ? __('End at the end of the cycle') : __('Keep renewing'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.($end
                ? __('Each ends (Terminated) on its next due date and gets no more payment notices: a hosting account is deleted from its server then, a domain expires at the registrar (its Auto renew is turned off).')
                : __('Each renews as usual again: payment notices and renewal emails come back (for a domain, Auto renew is turned on).')))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Service $service) => $service->update(['auto_terminate' => $end]),
                fn (Service $service) => $service->label,
            )->notify($end ? ':count service ends at the end of its cycle|:count services end at the end of their cycle' : ':count service renews again|:count services renew again'));
    }

    /** As Sync expiry and nameservers on each domain read from a registrar. */
    public static function syncDomains(): BulkAction
    {
        $skip = fn (Service $service) => match (true) {
            $service->type !== ServiceType::Domain => __('not a domain'),
            $service->status === ServiceStatus::Pending => __('pending'),
            ! app(DomainLifecycle::class)->readsRegistrar($service) => __('not read from a registrar'),
            default => null,
        };

        return BulkAction::make('syncDomains')
            ->label('Sync domains')
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading(__('Sync domains'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Reads each domain\'s expiry date and nameservers from its registrar. Nothing is changed there, and due dates stay as they are.'))
            ->modalSubmitActionLabel(__('Sync'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records,
                $skip,
                fn (Service $service) => app(DomainLifecycle::class)->sync($service),
                fn (Service $service) => $service->label,
            )->notify(':count domain synced|:count domains synced'));
    }
}
