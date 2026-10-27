<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Tickets;
use App\Ai\AiException;
use App\Ai\Assistant;
use App\Ai\TicketContext;
use App\Enums\AdminArea;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Actions\AiTextActions;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Tickets\TicketFiles;
use App\Models\CannedReply;
use App\Models\Client;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/** A ticket's messages with Reply (a canned reply or an AI draft to start from, files, the status after), Add note, Summarise, Change, Close and an AI knowledge base article. */
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
            $this->replyAction(),
            $this->noteAction(),
            $this->summaryAction(),
            ActionGroup::make([
                $this->changeAction(),
                $this->kbArticleAction(),
                Action::make('close')
                    ->label('Close')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (Ticket $record) => $record->status !== TicketStatus::Closed)
                    ->requiresConfirmation()
                    ->modalDescription(__('The client can still reply, which opens it again. No email is sent.'))
                    ->action(function (Ticket $record) {
                        app(Tickets::class)->close($record);
                        Notification::make()->title(__('Ticket closed'))->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Open again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Ticket $record) => $record->status === TicketStatus::Closed)
                    ->action(fn (Ticket $record) => app(Tickets::class)->update($record, ['status' => TicketStatus::Open])),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }

    private function replyAction(): Action
    {
        return Action::make('reply')
            ->label('Reply')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->modalHeading(fn (Ticket $record) => __('Reply to :number', ['number' => $record->number]))
            ->modalWidth('3xl')
            ->fillForm(['status' => TicketStatus::Answered->value])
            ->schema(fn (Ticket $record) => [
                Select::make('canned')
                    ->label('Canned reply')
                    ->placeholder(__('None'))
                    ->options(fn () => CannedReply::query()->orderBy('title')->pluck('title', 'id'))
                    ->searchable()
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn () => CannedReply::query()->exists())
                    ->afterStateUpdated(function (Set $set, ?string $state) use ($record) {
                        if ($state && ($canned = CannedReply::query()->find($state))) {
                            $set('body', Tickets::fill($canned->body, $record, auth()->user()));
                        }
                    }),
                AiTextActions::on(Textarea::make('body')
                    ->label('Message')
                    ->required()
                    ->rows(10)
                    ->maxLength(20000), clientLanguage: fn () => $record->client?->locale, write: [[
                        'name' => 'aiDraftReply',
                        'label' => __('Draft a reply'),
                        'feature' => 'ticket_reply',
                        'ask' => fn (Assistant $ai, string $draft) => $ai->ticketReply(new TicketContext($record), $draft),
                    ]]),
                TicketFiles::make(),
                Select::make('status')
                    ->label('Then set the status to')
                    ->options(TicketStatus::class)
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (Ticket $record, array $data) {
                app(Tickets::class)->reply($record, auth()->user(), $data['body'], TicketFiles::from($data), status: self::status($data['status']));
                Notification::make()->title(__('Reply sent'))->success()->send();
            });
    }

    /** Summarise: the AI assistant's one line, priority, department and mood; Keep stores the line and what the admin left chosen. */
    private function summaryAction(): Action
    {
        return Action::make('aiSummary')
            ->label('Summarise')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn () => Assistant::enabled('ticket_summary'))
            ->modalHeading(__('Summary by the AI assistant'))
            ->modalDescription(fn () => __('Suggested by :service. Keep what you agree with: the summary shows under the subject in the list until the client writes again.', ['service' => Assistant::provider()?->aiLabel()]))
            ->fillForm(function (Ticket $record, Action $action) {
                $departments = TicketDepartment::query()->ordered()->pluck('name', 'id');

                try {
                    $answer = app(Assistant::class)->ticketSummary(new TicketContext($record), $departments->values()->all(), app()->getLocale());
                } catch (AiException $e) {
                    Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
                    $action->cancel();

                    return [];
                }

                return [
                    'summary' => $answer['summary'],
                    'mood' => match ($answer['mood']) {
                        'angry' => __('The client sounds angry.'),
                        'worried' => __('The client sounds worried.'),
                        'calm' => __('The client sounds calm.'),
                        default => null,
                    },
                    'priority' => ($answer['priority'] ?? $record->priority)->value,
                    'department_id' => $answer['department'] !== null ? $departments->search($answer['department']) : $record->department_id,
                ];
            })
            ->schema([
                Textarea::make('summary')
                    ->label('Summary')
                    ->rows(2)
                    ->maxLength(300),
                TextInput::make('mood')
                    ->label('Mood')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (Get $get) => filled($get('mood'))),
                Select::make('priority')->options(TicketPriority::class)->required(),
                Select::make('department_id')
                    ->label('Department')
                    ->placeholder(__('None'))
                    ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id')),
            ])
            ->modalSubmitActionLabel(__('Keep'))
            ->action(function (Ticket $record, array $data) {
                app(Tickets::class)->update($record, [
                    'summary' => filled($data['summary'] ?? null) ? trim($data['summary']) : null,
                    'priority' => $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']),
                    'department_id' => $data['department_id'] ?: null,
                ]);
                Notification::make()->title(__('Saved'))->success()->send();
            });
    }

    /** An answered ticket as a knowledge base article: the AI's title and text, saved unpublished once the admin agrees. */
    private function kbArticleAction(): Action
    {
        return Action::make('aiKbArticle')
            ->label('Write a knowledge base article')
            ->icon(Heroicon::OutlinedBookOpen)
            ->visible(fn (Ticket $record) => Assistant::enabled('kb_article') && KbArticleResource::canCreate()
                && $record->messages()->public()->where('from_client', false)->exists())
            ->modalHeading(__('Knowledge base article from this ticket'))
            ->modalDescription(fn () => __('Written by :service from this ticket, without the client\'s details. Check every step: it is saved unpublished, for you to finish and publish.', ['service' => Assistant::provider()?->aiLabel()]))
            ->modalWidth('3xl')
            ->fillForm(function (Ticket $record, Action $action) {
                try {
                    $article = app(Assistant::class)->kbArticle(new TicketContext($record), Locales::default());
                } catch (AiException $e) {
                    Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
                    $action->cancel();

                    return [];
                }

                return $article;
            })
            ->schema([
                Select::make('kb_category_id')
                    ->label('Category')
                    ->placeholder(__('No category'))
                    ->options(fn () => KbCategory::query()->ordered()->get()->mapWithKeys(fn (KbCategory $category) => [$category->id => $category->name()])),
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(200),
                MarkdownEditor::make('body')
                    ->label('Text')
                    ->required()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']]),
            ])
            ->modalSubmitActionLabel(__('Save as draft'))
            ->action(function (array $data) {
                $article = KbArticle::query()->create([
                    'kb_category_id' => $data['kb_category_id'] ?: null,
                    'texts' => [Locales::default() => ['title' => $data['title'], 'body' => $data['body']]],
                    'published' => false,
                    'created_by' => auth()->id(),
                ]);

                Notification::make()->title(__('Article saved as a draft'))->success()
                    ->actions([Action::make('open')->label(__('Open'))->url(KbArticleResource::getUrl('edit', ['record' => $article]))])
                    ->send();
            });
    }

    private function noteAction(): Action
    {
        return Action::make('note')
            ->label('Add note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalDescription(__('Only admins see notes; the client is never shown or emailed them.'))
            ->schema([
                Textarea::make('body')
                    ->label('Note')
                    ->required()
                    ->rows(6)
                    ->maxLength(20000),
                TicketFiles::make(),
            ])
            ->modalSubmitActionLabel(__('Add note'))
            ->action(function (Ticket $record, array $data) {
                app(Tickets::class)->reply($record, auth()->user(), $data['body'], TicketFiles::from($data), internal: true);
                Notification::make()->title(__('Note added'))->success()->send();
            });
    }

    private function changeAction(): Action
    {
        return Action::make('change')
            ->label('Change')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->fillForm(fn (Ticket $record) => [
                'client_id' => $record->client_id,
                'status' => $record->status->value,
                'priority' => $record->priority->value,
                'department_id' => $record->department_id,
                'assigned_to' => $record->assigned_to,
                'service_id' => $record->service_id,
            ])
            ->schema(fn (Ticket $record) => [
                // Only a ticket from an unknown sender: link it to the client, who then sees it in the client area
                Select::make('client_id')
                    ->label('Client')
                    ->placeholder(__('Not a client'))
                    ->helperText(__('Emailed from :email. Once linked, the client sees this ticket in the client area and its emails go to the client\'s address.', ['email' => (string) $record->email]))
                    ->options(fn () => Client::query()->orderBy('last_name')->get()->mapWithKeys(fn (Client $c) => [$c->id => $c->displayName()]))
                    ->searchable()
                    ->visible($record->client_id === null),
                Select::make('status')->options(TicketStatus::class)->required(),
                Select::make('priority')->options(TicketPriority::class)->required(),
                Select::make('department_id')
                    ->label('Department')
                    ->options(fn () => TicketDepartment::query()->ordered()->pluck('name', 'id')),
                Select::make('assigned_to')
                    ->label('Assigned to')
                    ->placeholder(__('Nobody'))
                    ->options(fn () => User::query()->admins()->where('active', true)->orderBy('name')->get()
                        ->filter(fn (User $admin) => $admin->canUseArea(AdminArea::Support))
                        ->pluck('name', 'id')),
                Select::make('service_id')
                    ->label('Service')
                    ->placeholder(__('None'))
                    ->options(fn () => Service::query()->where('client_id', $record->client_id)->orderBy('label')->get()
                        ->mapWithKeys(fn (Service $s) => [$s->id => trim($s->label.' '.($s->domain !== $s->label ? $s->domain : ''))])),
            ])
            ->action(function (Ticket $record, array $data, Action $action) {
                try {
                    app(Tickets::class)->update($record, [
                        ...($record->client_id === null && filled($data['client_id'] ?? null) ? ['client_id' => (int) $data['client_id']] : []),
                        'status' => self::status($data['status']),
                        'priority' => $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']),
                        'department_id' => $data['department_id'] ?: null,
                        'assigned_to' => $data['assigned_to'] ?: null,
                        'service_id' => $data['service_id'] ?: null,
                    ]);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }

    private static function status(TicketStatus|string $status): TicketStatus
    {
        return $status instanceof TicketStatus ? $status : TicketStatus::from($status);
    }
}
