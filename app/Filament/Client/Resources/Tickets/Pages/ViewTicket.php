<?php

namespace App\Filament\Client\Resources\Tickets\Pages;

use App\Actions\Tickets;
use App\Enums\TicketStatus;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Filament\Tickets\TicketFiles;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** The client's ticket: its messages with Reply (which opens a closed ticket again) and Close. */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        /** @var Ticket $ticket */
        $ticket = $this->getRecord();

        return "{$ticket->number} · {$ticket->subject}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->modalWidth('2xl')
                ->modalDescription(fn (Ticket $record) => $record->status === TicketStatus::Closed ? __('This ticket is closed; your reply opens it again.') : null)
                ->schema([
                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(8)
                        ->maxLength(20000),
                    TicketFiles::make(),
                ])
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (Ticket $record, array $data) {
                    app(Tickets::class)->reply($record, filament()->auth()->user(), $data['body'], TicketFiles::from($data), asClient: true);
                    Notification::make()->title(__('Reply sent'))->success()->send();
                }),
            Action::make('close')
                ->label('Close ticket')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->visible(fn (Ticket $record) => $record->status !== TicketStatus::Closed)
                ->requiresConfirmation()
                ->modalDescription(__('Close it if you need nothing more. You can still reply later, which opens it again.'))
                ->action(function (Ticket $record) {
                    app(Tickets::class)->close($record);
                    Notification::make()->title(__('Ticket closed'))->success()->send();
                }),
        ];
    }
}
