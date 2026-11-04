<?php

namespace App\Filament\Resources\ModuleQueue\Pages;

use App\Actions\ModuleQueue;
use App\Filament\Resources\ModuleQueue\ModuleQueueItemResource;
use App\Models\ModuleQueueItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/** The open items first (with Retry all), Resolved for the closed ones and how. */
class ListModuleQueue extends ListRecords
{
    protected static string $resource = ModuleQueueItemResource::class;

    public function mount(): void
    {
        app(ModuleQueue::class)->sweep(); // what the service no longer needs leaves the list at once

        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Module queue');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $tries = (int) config('gaiacp.automation.module_retries');

        return $tries > 0
            ? __('Set up, unsuspend and suspensions for non-payment are retried every :minutes minutes, :count times. Terminate and domain renewals wait for you.', ['minutes' => ModuleQueue::RETRY_MINUTES, 'count' => $tries])
            : __('Nothing is retried by itself (Settings → Automation → Retry failed actions).');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')
                ->label('Retry all')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => ModuleQueueItem::query()->open()->exists())
                ->requiresConfirmation()
                ->modalDescription(__('Every open item is tried again now, terminations and domain renewals too.'))
                ->action(function () {
                    $queue = app(ModuleQueue::class);
                    $items = ModuleQueueItem::query()->open()->with('service')->orderBy('id')->get();
                    $fixed = $items->filter(fn (ModuleQueueItem $item) => $queue->retry($item))->count();
                    $failed = $items->count() - $fixed;

                    Notification::make()
                        ->title(__(':fixed done, :failed still failing', ['fixed' => $fixed, 'failed' => $failed]))
                        ->status($failed > 0 ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('In the queue'))
                ->badge(ModuleQueueItem::query()->open()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('resolved_at')),
            'resolved' => Tab::make(__('Resolved'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('resolved_at')),
        ];
    }
}
