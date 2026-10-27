<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Actions\ClientAreaAccess;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** The "Client area" menu on a client: invite, turn the login off / on, log in as the client. */
class ClientAreaActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('inviteToClientArea')
                ->label(fn (Client $record) => $record->user ? __('Send a new invitation') : __('Invite to client area'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->requiresConfirmation()
                ->modalDescription(fn (Client $record) => self::adminWithEmail($record)
                    ? __('An admin user logs in with :email. This links them to this client: their admin login opens this client area too, with the same password. No email is sent.', ['email' => $record->email])
                    : __('Emails :email a link to choose a password for the client area (Client Area Invitation template). The link works once, for 48 hours.', ['email' => $record->email]))
                ->hidden(fn (Client $record) => ClientAreaAccess::isLinkedAdmin($record))
                ->disabled(fn (Client $record) => blank($record->email))
                ->action(function (Client $record) {
                    try {
                        $sent = app(ClientAreaAccess::class)->invite($record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Not invited'))->body($e->getMessage())->send();

                        return;
                    }

                    if (ClientAreaAccess::isLinkedAdmin($record)) {
                        Notification::make()->success()->title(__('Linked to the admin user :email', ['email' => $record->email]))->send();

                        return;
                    }

                    $sent
                        ? Notification::make()->success()->title(__('Invitation sent to :email', ['email' => $record->email]))->send()
                        : Notification::make()->warning()->title(__('The login is ready, but the email could not be sent'))->body(__('See the email log.'))->send();
                }),
            Action::make('disableClientArea')
                ->label(fn (Client $record) => ClientAreaAccess::isLinkedAdmin($record) ? __('Unlink admin user') : __('Turn off login'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(fn (Client $record) => ClientAreaAccess::isLinkedAdmin($record)
                    ? __('The admin user :email no longer opens this client area. Their admin login stays as it is.', ['email' => $record->user->email])
                    : __('The client can no longer log in to the client area until you turn it on again.'))
                ->visible(fn (Client $record) => $record->user?->active === true)
                ->action(function (Client $record) {
                    $linked = ClientAreaAccess::isLinkedAdmin($record);
                    app(ClientAreaAccess::class)->disable($record);
                    Notification::make()->success()->title($linked ? __('Admin user unlinked') : __('Client area login turned off'))->send();
                }),
            Action::make('enableClientArea')
                ->label('Turn on login')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn (Client $record) => $record->user?->active === false)
                ->action(function (Client $record) {
                    app(ClientAreaAccess::class)->enable($record);
                    Notification::make()->success()->title(__('Client area login turned on'))->send();
                }),
            Action::make('logInAsClient')
                ->label('Log in as client')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                // Also before the client is invited: the login made for it stays turned off (ClientAreaAccess::logInAs())
                ->disabled(fn (Client $record) => $record->user === null && blank($record->email))
                ->action(function (Client $record, $livewire) {
                    try {
                        app(ClientAreaAccess::class)->logInAs($record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Not opened'))->body($e->getMessage())->send();

                        return;
                    }

                    $livewire->redirect(Filament::getPanel('client')->getUrl());
                }),
        ])
            ->label('Client area')
            ->icon(Heroicon::OutlinedUserCircle)
            ->button()
            ->color('gray');
    }

    /** Whether inviting this client links an admin user who logs in with the client's email. */
    private static function adminWithEmail(Client $client): bool
    {
        return $client->user === null && filled($client->email)
            && User::query()->where('email', $client->email)->where('role', '!=', UserRole::Client)->whereNull('client_id')->exists();
    }
}
