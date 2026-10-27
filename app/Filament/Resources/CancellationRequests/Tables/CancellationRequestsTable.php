<?php

namespace App\Filament\Resources\CancellationRequests\Tables;

use App\Actions\CancellationRequests;
use App\Enums\CancellationType;
use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\CancellationRequest;
use App\Providers\AppServiceProvider;
use App\Provisioning\ProvisioningException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Like WHMCS's list: date, service and client, reason, type, the date it ends; Approve or Decline on the open ones. */
class CancellationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('service', 'client', 'domainService', 'decidedBy'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('service.label')
                    ->label('Service')
                    ->formatStateUsing(fn (CancellationRequest $record) => $record->serviceName())
                    ->description(fn (CancellationRequest $record) => $record->client->name)
                    ->url(fn (CancellationRequest $record) => ServiceResource::getUrl('edit', ['record' => $record->service_id]))
                    ->searchable(['label', 'domain'])
                    ->wrap(),
                TextColumn::make('reason')
                    ->placeholder('–')
                    ->limit(120)
                    ->wrap(),
                TextColumn::make('type')
                    ->label('When')
                    ->description(fn (CancellationRequest $record) => $record->domainService
                        ? __('Do not renew :domain', ['domain' => $record->domainService->domain])
                        : null)
                    ->wrap(),
                TextColumn::make('ends')
                    ->label('Ends on')
                    ->state(fn (CancellationRequest $record) => $record->type === CancellationType::EndOfPeriod
                        ? $record->service->next_due_date?->format(AppServiceProvider::dateFormat())
                        : __('When approved')),
                TextColumn::make('status')
                    ->badge()
                    ->description(fn (CancellationRequest $record) => $record->decided_at
                        ? trim($record->decided_at->format(AppServiceProvider::dateFormat()).' '.$record->decidedBy?->name)
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (CancellationRequest $record) => $record->status === ChangeRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading(fn (CancellationRequest $record) => __('Cancel :service?', ['service' => $record->serviceName()]))
                    ->modalDescription(fn (CancellationRequest $record) => self::approveDescription($record))
                    ->modalSubmitActionLabel(__('Approve'))
                    ->action(function (CancellationRequest $record) {
                        try {
                            app(CancellationRequests::class)->approve($record);
                        } catch (ProvisioningException $e) {
                            Notification::make()->title(__('The server did not terminate the account'))->body($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title(__('Request approved'))->success()->send();
                    }),
                Action::make('decline')
                    ->label('Decline')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (CancellationRequest $record) => $record->status === ChangeRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription(__('The service stays as it is. The client gets the Cancellation Request Declined email, if it is on.'))
                    ->modalSubmitActionLabel(__('Decline'))
                    ->action(function (CancellationRequest $record) {
                        app(CancellationRequests::class)->reject($record);
                        Notification::make()->title(__('Request declined'))->success()->send();
                    }),
            ]);
    }

    private static function approveDescription(CancellationRequest $record): string
    {
        $text = $record->type === CancellationType::Immediate
            ? __('It is terminated now, on its server too, and taken off unpaid payment notices.')
            : __('It is marked Auto-terminate: no more payment notices, and it is terminated on :date.', [
                'date' => $record->service->next_due_date?->format(AppServiceProvider::dateFormat()),
            ]);

        if ($record->domainService) {
            $text .= ' '.__('Auto renew is turned off on :domain.', ['domain' => $record->domainService->domain]);
        }

        return $text;
    }
}
