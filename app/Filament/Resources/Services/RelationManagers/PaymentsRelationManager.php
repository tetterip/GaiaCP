<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Actions\ReversePayment;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Payment;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (Payment $record) => Money::format($record->amount_cents, $record->currency)),
                TextColumn::make('method')
                    ->badge(),
                TextColumn::make('reference')
                    ->placeholder('—'),
                TextColumn::make('due_date_after')
                    ->label('Covers')
                    ->formatStateUsing(fn (Payment $record) => $record->due_date_before->format(AppServiceProvider::dateFormat()).' → '.$record->due_date_after->format(AppServiceProvider::dateFormat())),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payment $record) => __('Deletes this payment and sets the due date back to :date.', ['date' => $record->due_date_before->format(AppServiceProvider::dateFormat())]))
                    ->visible(fn (Payment $record) => ReversePayment::canReverse($record))
                    ->action(function (Payment $record) {
                        app(ReversePayment::class)->handle($record);

                        Notification::make()->title(__('Payment reversed'))->success()->send();

                        // The edit form above shows the due date; reload it.
                        $this->redirect(ServiceResource::getUrl('edit', ['record' => $this->getOwnerRecord()]));
                    }),
            ]);
    }
}
