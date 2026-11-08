<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Actions\RefundPayment;
use App\Actions\ReversePayment;
use App\Credit\ClientCredit;
use App\Enums\ServiceType;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Payment;
use App\Providers\AppServiceProvider;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
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
                    ->formatStateUsing(fn (Payment $record) => Money::format($record->amount_cents, $record->currency))
                    ->description(fn (Payment $record) => match (true) {
                        $record->refund_of !== null => __('Refund'),
                        $record->refunds->isNotEmpty() => __('Refunded'),
                        default => null,
                    }),
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
            ->modifyQueryUsing(fn ($query) => $query->with('refunds'))
            ->defaultSort('id', 'desc')
            ->recordActions([
                // Money given back, for any payment; the due date stays (ReversePayment is for one recorded by mistake)
                Action::make('refund')
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->color('warning')
                    ->modalHeading(__('Refund payment'))
                    ->modalDescription(fn (Payment $record) => __('Records that :amount of :date was given back, on the day you choose, and issues a credit note if an invoice was issued for it. Nothing is sent to Stripe, PayPal or the bank: refund it there yourself. The due date and the service stay as they are: cancel or terminate the service if it ends.', [
                        'amount' => Money::format($record->amount_cents, $record->currency),
                        'date' => $record->paid_on->format(AppServiceProvider::dateFormat()),
                    ]))
                    ->schema(fn (Payment $record) => [
                        Radio::make('to')
                            ->label('Where the money goes')
                            ->options($record->method === ClientCredit::METHOD
                                ? [RefundPayment::TO_CREDIT => __('Back into the client\'s balance')]
                                : array_filter([
                                    RefundPayment::ELSEWHERE => __('Paid back outside GaiaCP'),
                                    RefundPayment::TO_CREDIT => ClientCredit::enabled() ? __('Into the client\'s balance') : null,
                                ]))
                            ->default($record->method === ClientCredit::METHOD ? RefundPayment::TO_CREDIT : RefundPayment::ELSEWHERE)
                            ->required(),
                        DatePicker::make('on')
                            ->label('Refunded on')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                        Textarea::make('notes')
                            ->rows(2),
                    ])
                    ->visible(fn (Payment $record) => RefundPayment::canRefund($record))
                    ->action(function (Payment $record, array $data) {
                        app(RefundPayment::class)->handle($record, $data['to'], CarbonImmutable::parse($data['on']), $data['notes'] ?? null);

                        Notification::make()->title(__('Payment refunded'))->success()->send();
                    }),
                Action::make('reverse')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Payment $record) => __('Deletes this payment and sets the due date back to :date.', ['date' => $record->due_date_before->format(AppServiceProvider::dateFormat())])
                        .($record->service?->type === ServiceType::Domain ? ' '.__('A renewal it made at the registrar stays: the registrar is not asked to undo it.') : ''))
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
