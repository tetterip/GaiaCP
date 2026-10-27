<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Actions\Quotes;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A quote: changed while it is a draft or sent, then read only. Send emails its link (saving the changes first),
 * Accept for the client places the order when they said yes another way, Duplicate starts a new one from it.
 *
 * @property Quote $record
 */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return QuoteResource::formData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Quote $record */
        $record->update(Quotes::attributes($data));

        return $record;
    }

    protected function getFormActions(): array
    {
        return $this->record->status->isOpen() ? parent::getFormActions() : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(fn () => $this->record->status === QuoteStatus::Sent ? __('Send again') : __('Send'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn () => $this->record->status->isOpen())
                ->requiresConfirmation()
                ->modalHeading(fn () => __('Send quote :number', ['number' => $this->record->number]))
                ->modalDescription(fn () => __('It is saved and emailed to :email with the link to its page.', ['email' => $this->record->recipientEmail() ?: '–']))
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        app(Quotes::class)->send($this->record->refresh());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Quote sent to :email', ['email' => $this->record->recipientEmail()]))->send();
                }),
            Action::make('page')
                ->label('Open page')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn () => $this->record->status !== QuoteStatus::Draft)
                ->url(fn () => $this->record->url(), shouldOpenInNewTab: true),
            Action::make('order')
                ->label('Open order')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('gray')
                ->visible(fn () => $this->record->order_id !== null && OrderResource::canAccess())
                ->url(fn () => OrderResource::getUrl('view', ['record' => $this->record->order_id])),
            ActionGroup::make([
                Action::make('accept')
                    ->label('Accept for the client')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->visible(fn () => $this->record->status->isOpen())
                    ->requiresConfirmation()
                    ->modalDescription(__('For a client who said yes another way (phone, email): the order is placed now with its payment notice, and a lead becomes a client with the client area invitation.'))
                    ->action(function () {
                        try {
                            $quote = app(Quotes::class)->accept($this->record, byAdmin: true);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Accepted: order #:id placed', ['id' => $quote->order_id]))->send();
                        $this->redirect(QuoteResource::getUrl('edit', ['record' => $quote]));
                    }),
                Action::make('decline')
                    ->label('Mark as declined')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->visible(fn () => $this->record->status === QuoteStatus::Sent)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->rows(2)
                            ->maxLength(255),
                    ])
                    ->action(function (array $data) {
                        app(Quotes::class)->decline($this->record, $data['reason'] ?? null);
                        Notification::make()->success()->title(__('Marked as declined'))->send();
                        $this->redirect(QuoteResource::getUrl('edit', ['record' => $this->record]));
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->action(function () {
                        $copy = app(Quotes::class)->duplicate($this->record);
                        Notification::make()->success()->title(__('Quote :number created from it', ['number' => $copy->number]))->send();
                        $this->redirect(QuoteResource::getUrl('edit', ['record' => $copy]));
                    }),
                Action::make('recipient')
                    ->label(fn () => $this->record->client_id ? __('Open client') : __('Open lead'))
                    ->icon(Heroicon::OutlinedUser)
                    ->visible(fn () => $this->recipientUrl() !== null)
                    ->url(fn () => $this->recipientUrl()),
                DeleteAction::make()
                    ->hidden(fn () => $this->record->status === QuoteStatus::Accepted),
            ]),
        ];
    }

    private function recipientUrl(): ?string
    {
        if ($this->record->client_id) {
            return ClientResource::canAccess() ? ClientResource::getUrl('edit', ['record' => $this->record->client_id]) : null;
        }

        return $this->record->lead_id ? LeadResource::getUrl('edit', ['record' => $this->record->lead_id]) : null;
    }
}
