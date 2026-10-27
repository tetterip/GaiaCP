<?php

namespace App\Filament\Resources\ClientMessages\Tables;

use App\Filament\Resources\ClientMessages\ClientMessageActions;
use App\Models\ClientMessage;
use App\Providers\AppServiceProvider;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount([
                'recipients',
                'recipients as sent_count' => fn ($q) => $q->whereNotNull('sent_at'),
                'recipients as failed_count' => fn ($q) => $q->whereNotNull('failed_at'),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->state(fn (ClientMessage $record) => $record->subject())
                    ->description(fn (ClientMessage $record) => $record->kind->getLabel())
                    ->wrap(),
                TextColumn::make('email')
                    ->label('Email')
                    ->state(fn (ClientMessage $record) => self::emailState($record))
                    ->badge()
                    ->color(fn (ClientMessage $record) => match (true) {
                        ! $record->send_email => 'gray',
                        ! $record->isQueued() => 'warning',
                        $record->finished_at === null => 'info',
                        default => $record->failed_count > 0 ? 'danger' : 'success',
                    }),
                TextColumn::make('client_area')
                    ->label('Client area')
                    ->state(fn (ClientMessage $record) => self::clientAreaState($record))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date(AppServiceProvider::dateFormat())
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ClientMessageActions::send(),
                    ClientMessageActions::test(),
                    DeleteAction::make()->hidden(fn (ClientMessage $record) => $record->isQueued()),
                ]),
            ])
            ->emptyStateDescription(__('Email the clients on a server, with a product or a domain ending, or show them an announcement in the client area.'));
    }

    private static function emailState(ClientMessage $record): string
    {
        return match (true) {
            ! $record->send_email => __('Not emailed'),
            ! $record->isQueued() => __('Draft'),
            $record->finished_at === null => __('Sending: :sent of :total', ['sent' => $record->sent_count, 'total' => $record->recipients_count]),
            $record->failed_count > 0 => __('Sent to :sent, :failed failed', ['sent' => $record->sent_count, 'failed' => $record->failed_count]),
            default => __('Sent to :sent', ['sent' => $record->sent_count]),
        };
    }

    private static function clientAreaState(ClientMessage $record): ?string
    {
        if (! $record->show_in_client_area) {
            return null;
        }

        $date = fn ($d) => $d?->format(AppServiceProvider::dateFormat());

        return match (true) {
            $record->show_until !== null && $record->show_until->lt(today()) => __('Ended :date', ['date' => $date($record->show_until)]),
            $record->show_from !== null && $record->show_from->gt(today()) => __('From :date', ['date' => $date($record->show_from)]),
            $record->show_until !== null => __('Showing until :date', ['date' => $date($record->show_until)]),
            default => __('Showing'),
        };
    }
}
