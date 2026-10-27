<?php

namespace App\Filament\Client\Pages;

use App\Actions\Tickets;
use App\Enums\TicketStatus;
use App\Filament\Client\Concerns\OpenToVisitors;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A ticket without a client, at its secret link (Ticket::publicUrl(), in each of its emails): its sender reads the
 * conversation (never the admins' notes), replies and closes it, without a login. Once an admin links the ticket to a
 * client the link stops working and the client area has it.
 */
class VisitorTicket extends Page
{
    use OpenToVisitors;

    protected static ?string $slug = 'support-ticket';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.client.visitor-ticket';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    /** Replies one ticket takes in an hour from its page. */
    public const REPLIES_PER_HOUR = 10;

    public string $token = '';

    public static function getRoutePath(Panel $panel): string
    {
        return '/support/ticket/{token}';
    }

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->ticket();
    }

    public function ticket(): Ticket
    {
        return Ticket::query()->whereNull('client_id')->where('access_token', $this->token)->firstOrFail();
    }

    public function getTitle(): string|Htmlable
    {
        $ticket = $this->ticket();

        return "{$ticket->number} · {$ticket->subject}";
    }

    /** @return array<string, string> */
    public function details(): array
    {
        $ticket = $this->ticket();

        return array_filter([
            __('Status') => $ticket->status->getLabel(),
            __('Department') => $ticket->department?->name,
            __('Email address') => $ticket->email,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->modalWidth('2xl')
                ->modalDescription(fn () => $this->ticket()->status === TicketStatus::Closed ? __('This ticket is closed; your reply opens it again.') : null)
                ->schema([
                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(8)
                        ->maxLength(20000),
                ])
                ->modalSubmitActionLabel(__('Send'))
                ->action(function (array $data) {
                    $ticket = $this->ticket();

                    if (! RateLimiter::attempt("visitor-ticket:{$ticket->id}", self::REPLIES_PER_HOUR, fn () => true, 3600)) {
                        Notification::make()->title(__('Too many messages from here. Please try again later.'))->danger()->send();

                        return;
                    }

                    app(Tickets::class)->replyFromVisitor($ticket, $data['body']);
                    Notification::make()->title(__('Reply sent'))->success()->send();
                }),
            Action::make('close')
                ->label('Close ticket')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->visible(fn () => $this->ticket()->status !== TicketStatus::Closed)
                ->requiresConfirmation()
                ->modalDescription(__('Close it if you need nothing more. You can still reply later, which opens it again.'))
                ->action(function () {
                    app(Tickets::class)->close($this->ticket());
                    Notification::make()->title(__('Ticket closed'))->success()->send();
                }),
        ];
    }
}
