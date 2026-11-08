<?php

namespace App\Filament\Actions;

use App\Actions\CancellationRequests;
use App\Actions\UpgradeRequests;
use App\Enums\ChangeRequestStatus;
use App\Models\CancellationRequest;
use App\Models\UpgradeRequest;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mass actions on cancellation and upgrade requests, each as its own Approve or Decline. An upgrade is approved one at a
 * time, since each asks for its new price.
 */
class ChangeRequestBulkActions
{
    public const MAX = 100;

    public static function cancellations(): BulkActionGroup
    {
        $skip = fn (CancellationRequest $request) => $request->status === ChangeRequestStatus::Pending ? null : __('already decided');
        $label = fn (CancellationRequest $request) => $request->serviceName();

        return BulkActionGroup::make([
            BulkAction::make('approve')
                ->label('Approve')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Approve cancellation requests'))
                ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                    .__('Each is approved as its own Approve would: one asked for now is terminated now, on its server too; one for the end of its cycle is marked Auto-terminate. One the server refuses stays pending and is named below.'))
                ->modalSubmitActionLabel(__('Approve'))
                ->deselectRecordsAfterCompletion()
                ->action(fn (Collection $records) => BulkOutcome::run(
                    $records->load('service'),
                    $skip,
                    fn (CancellationRequest $request) => app(CancellationRequests::class)->approve($request),
                    $label,
                )->notify(':count request approved|:count requests approved')),
            self::decline(
                $skip,
                $label,
                fn (CancellationRequest $request) => app(CancellationRequests::class)->reject($request),
                __('The services stay as they are. Each client gets the Cancellation Request Declined email, if it is on.'),
            ),
        ]);
    }

    public static function upgrades(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::decline(
                fn (UpgradeRequest $request) => $request->status === ChangeRequestStatus::Pending ? null : __('already decided'),
                fn (UpgradeRequest $request) => $request->serviceName(),
                fn (UpgradeRequest $request) => app(UpgradeRequests::class)->reject($request),
                __('The services stay as they are. Each client gets the Upgrade Request Declined email, if it is on.'),
            ),
        ]);
    }

    private static function decline(callable $skip, callable $label, callable $reject, string $text): BulkAction
    {
        return BulkAction::make('decline')
            ->label('Decline')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Decline requests'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '.$text)
            ->modalSubmitActionLabel(__('Decline'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run($records, $skip, $reject, $label)
                ->notify(':count request declined|:count requests declined'));
    }
}
