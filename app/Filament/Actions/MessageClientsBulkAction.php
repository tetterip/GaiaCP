<?php

namespace App\Filament\Actions;

use App\Actions\SendClientMessages;
use App\Enums\ClientMessageKind;
use App\Filament\Resources\ClientMessages\ClientMessageResource;
use App\Models\ClientMessage;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Send message" on the clients or orders picked in a list: a Clients → Messages to clients message to exactly those
 * clients (each once), sent by the cron a few a minute like any other, so it stays in that list with its recipients.
 * Only for admins who may open Messages to clients (its area), whatever list it is on.
 */
class MessageClientsBulkAction
{
    /** @param  callable(Collection<int, Model>): array<int>  $clientIds  the clients of the picked records */
    public static function make(callable $clientIds): BulkAction
    {
        return BulkAction::make('sendMessage')
            ->label('Send message')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->authorize(fn () => ClientMessageResource::canAccess())
            ->modalHeading(__('Send message'))
            ->modalDescription(fn (Collection $records) => trans_choice('Emailed to :count client, each once, :rate a minute. It stays under Clients → Messages to clients.|Emailed to :count clients, each once, :rate a minute. It stays under Clients → Messages to clients.', count(array_unique($clientIds($records))), [
                'rate' => (int) config('gaiacp.automation.client_messages_per_minute', 30),
            ]))
            ->schema([
                Radio::make('kind')
                    ->options(ClientMessageKind::class)
                    ->default(ClientMessageKind::Service)
                    ->required(),
                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(255),
                MarkdownEditor::make('body')
                    ->label('Message')
                    ->required()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                    ->helperText(__('Placeholders: {$client_first_name}, {$client_name}, {$company_name}.')),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) use ($clientIds) {
                $message = ClientMessage::query()->create([
                    'kind' => $data['kind'],
                    'texts' => [Locales::default() => ['subject' => $data['subject'], 'body' => $data['body']]],
                    'filters' => ['clients' => array_values(array_unique($clientIds($records)))],
                    'send_email' => true,
                    'show_in_client_area' => false,
                    'created_by' => auth()->id(),
                ]);

                $count = app(SendClientMessages::class)->queue($message);

                Notification::make()->success()
                    ->title(trans_choice('Sending to :count client|Sending to :count clients', $count))
                    ->body($count < count(array_unique($clientIds($records))) ? __('Clients without an email address, or who turned off informational emails, are left out.') : null)
                    ->actions([Action::make('open')->label(__('Open'))->url(ClientMessageResource::getUrl('edit', ['record' => $message]))])
                    ->send();
            });
    }
}
