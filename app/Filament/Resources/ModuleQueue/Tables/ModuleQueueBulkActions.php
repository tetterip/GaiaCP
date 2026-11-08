<?php

namespace App\Filament\Resources\ModuleQueue\Tables;

use App\Actions\ModuleQueue;
use App\Filament\Actions\BulkOutcome;
use App\Models\ModuleQueueItem;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/** Retry or Mark resolved on the queue items picked in a list, item by item as their own buttons do. */
class ModuleQueueBulkActions
{
    public const MAX = 100;

    public static function group(): BulkActionGroup
    {
        return BulkActionGroup::make([self::retry(), self::resolve()]);
    }

    /** Never a terminate: deleting an account from its server asks for your password, one at a time. */
    public static function retry(): BulkAction
    {
        $skip = fn (ModuleQueueItem $item) => match (true) {
            $item->resolved_at !== null => __('already resolved'),
            $item->action === ModuleQueueItem::TERMINATE => __('a termination: retry it on its own'),
            default => null,
        };

        return BulkAction::make('retry')
            ->label('Retry')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading(__('Retry'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Each one is tried again on its server or registrar; a domain renewal is read from the registrar first. One that still fails stays in the queue and is named below.'))
            ->modalSubmitActionLabel(__('Retry'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records->load('service'),
                $skip,
                function (ModuleQueueItem $item) {
                    if (! app(ModuleQueue::class)->retry($item)) {
                        throw new RuntimeException((string) $item->error);
                    }
                },
                fn (ModuleQueueItem $item) => trim(($item->service->label ?? '').' '.$item->actionLabel()),
            )->notify(':count action done|:count actions done'));
    }

    public static function resolve(): BulkAction
    {
        $skip = fn (ModuleQueueItem $item) => $item->resolved_at !== null ? __('already resolved') : null;

        return BulkAction::make('resolve')
            ->label('Mark resolved')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Mark resolved'))
            ->modalDescription(fn (Collection $records) => BulkOutcome::preview($records, $skip).' '
                .__('Nothing is sent to the server: use it when you did it there by hand, or it is no longer needed.'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run(
                $records->load('service'),
                $skip,
                fn (ModuleQueueItem $item) => app(ModuleQueue::class)->markResolved($item),
                fn (ModuleQueueItem $item) => trim(($item->service->label ?? '').' '.$item->actionLabel()),
            )->notify(':count item marked resolved|:count items marked resolved'));
    }
}
