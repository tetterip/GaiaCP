<?php

namespace App\Filament\Resources\ModuleQueue\Tables;

use App\Actions\ModuleQueue;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\ModuleQueueItem;
use App\Providers\AppServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Like WHMCS's Module Queue: client / service, module / action, failure reason and last try; Retry or Mark resolved. */
class ModuleQueueTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service.client', 'resolvedBy'))
            ->columns([
                TextColumn::make('service.label')
                    ->label('Client / service')
                    ->formatStateUsing(fn (ModuleQueueItem $record) => $record->service?->client?->name)
                    ->description(fn (ModuleQueueItem $record) => trim($record->service?->label.($record->service?->domain && $record->service->domain !== $record->service->label ? ' - '.$record->service->domain : '')))
                    ->url(fn (ModuleQueueItem $record) => $record->service_id ? ServiceResource::getUrl('edit', ['record' => $record->service_id]) : null)
                    ->searchable(['label', 'domain'])
                    ->wrap(),
                TextColumn::make('action')
                    ->label('Module / action')
                    ->formatStateUsing(fn (ModuleQueueItem $record) => $record->actionLabel())
                    ->description(fn (ModuleQueueItem $record) => $record->module ?: $record->service?->type?->getLabel())
                    ->wrap(),
                TextColumn::make('error')
                    ->label('Failure reason')
                    ->limit(200)
                    ->wrap()
                    ->description(fn (ModuleQueueItem $record) => __('Last try :time, :count in all', [
                        'time' => $record->last_attempt_at->diffForHumans(),
                        'count' => $record->attempts,
                    ])),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->formatStateUsing(fn (ModuleQueueItem $record) => $record->resolutionLabel())
                    ->description(fn (ModuleQueueItem $record) => $record->resolved_at
                        ? trim($record->resolved_at->format(AppServiceProvider::dateFormat().' H:i').' '.$record->resolvedBy?->name)
                        : null)
                    ->placeholder('–')
                    ->visible(fn ($livewire) => ($livewire->activeTab ?? null) === 'resolved'),
            ])
            ->defaultSort('last_attempt_at', 'desc')
            // Nothing open is good news: a green tick; the Resolved tab keeps the plain empty state
            ->emptyState(fn ($livewire) => ($livewire->activeTab ?? 'open') === 'resolved' ? null : view('filament.services.module-queue-empty'))
            ->emptyStateHeading(__('Nothing resolved yet'))
            ->emptyStateDescription(__('A panel or registrar action that fails (set up, suspend, unsuspend, terminate, domain renewal) waits here to be retried.'))
            ->toolbarActions([ModuleQueueBulkActions::group()])
            ->maxSelectableRecords(ModuleQueueBulkActions::MAX)
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (ModuleQueueItem $record) => $record->resolved_at === null)
                    ->requiresConfirmation(fn (ModuleQueueItem $record) => in_array($record->action, [ModuleQueueItem::TERMINATE, ModuleQueueItem::RENEW], true))
                    ->modalDescription(fn (ModuleQueueItem $record) => $record->action === ModuleQueueItem::RENEW
                        ? __('The domain is read from the registrar first, and renewed only if it still expires before the date it is paid until.')
                        : __('The account is removed from the server.'))
                    // Deleting an account from the server asks who is at the keyboard, as Terminate does
                    ->schema(fn (ModuleQueueItem $record) => $record->action === ModuleQueueItem::TERMINATE ? [self::passwordField()] : [])
                    ->action(function (ModuleQueueItem $record) {
                        if (app(ModuleQueue::class)->retry($record)) {
                            Notification::make()->title(__('Done'))->success()->send();

                            return;
                        }

                        Notification::make()->title(__('Still failing'))->body($record->error)->danger()->persistent()->send();
                    }),
                Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (ModuleQueueItem $record) => $record->resolved_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('Nothing is sent to the server: use it when you did it there by hand, or it is no longer needed.'))
                    ->action(function (ModuleQueueItem $record) {
                        app(ModuleQueue::class)->markResolved($record);
                        Notification::make()->title(__('Marked resolved'))->success()->send();
                    }),
            ]);
    }

    /** "Your password", asked before a retry that deletes an account from its server (as the service's Terminate). */
    public static function passwordField(): TextInput
    {
        return TextInput::make('confirm_password')
            ->label('Your password')
            ->password()
            ->autocomplete('current-password')
            ->required()
            ->currentPassword();
    }
}
