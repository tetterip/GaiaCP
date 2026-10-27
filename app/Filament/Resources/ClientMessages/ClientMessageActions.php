<?php

namespace App\Filament\Resources\ClientMessages;

use App\Actions\SendClientMessages;
use App\Models\ClientMessage;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

/** Send and Send test, on the list and on the message's page (which saves the form first). */
final class ClientMessageActions
{
    public static function send(): Action
    {
        return Action::make('send')
            ->label('Send')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (ClientMessage $record) => $record->send_email && ! $record->isQueued())
            ->requiresConfirmation()
            ->modalHeading(__('Send this message?'))
            ->modalDescription(fn (ClientMessage $record) => __(':count client(s) get the email, :rate a minute. It can\'t be stopped or sent again.', [
                'count' => $record->emailClients()->count(),
                'rate' => (int) config('gaiacp.automation.client_messages_per_minute', 30),
            ]))
            ->action(function (ClientMessage $record, $livewire) {
                if ($livewire instanceof EditRecord) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }

                $count = app(SendClientMessages::class)->queue($record);

                Notification::make()->success()
                    ->title(__('Sending to :count client(s)', ['count' => $count]))
                    ->body(__('The cron sends them a few a minute; the Recipients list below shows each one.'))
                    ->send();
            });
    }

    public static function test(): Action
    {
        return Action::make('test')
            ->label('Send test to me')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->visible(fn (ClientMessage $record) => $record->send_email)
            ->modalDescription(fn () => __('Sent to :email as a client with your name would get it.', ['email' => auth()->user()?->email]))
            ->schema([
                Select::make('locale')
                    ->label('Language')
                    ->options(Locales::available())
                    ->default(Locales::default())
                    ->selectablePlaceholder(false)
                    ->visible(Locales::multiple()),
            ])
            ->action(function (ClientMessage $record, array $data, $livewire) {
                if ($livewire instanceof EditRecord && ! $record->isQueued()) {
                    $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record->refresh();
                }

                try {
                    app(SendClientMessages::class)->test($record, auth()->user(), $data['locale'] ?? null);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('The email could not be sent'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Test sent to :email', ['email' => auth()->user()->email]))->send();
            });
    }
}
