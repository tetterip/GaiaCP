<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Actions\ClientAreaAccess;
use App\Enums\MailTemplate;
use App\Filament\Forms\PasswordInput;
use App\Models\Client;
use App\Support\MailTemplates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/** The "Client area" menu on a client: invite, set the password or send a reset link, turn the login off / on, log in as the client. */
class ClientAreaActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('inviteToClientArea')
                ->label(fn (Client $record) => $record->user ? __('Send a new invitation') : __('Invite to client area'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->requiresConfirmation()
                ->modalDescription(fn (Client $record) => ClientAreaAccess::adminWithEmail($record)
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
            Action::make('setClientAreaPassword')
                ->label('Set password')
                ->icon(Heroicon::OutlinedKey)
                ->modalDescription(fn (Client $record) => $record->user
                    ? __('The client logs in with this password from now on. Every browser signed in to their client area is signed out.')
                    : __('Creates the client area login of :email with this password.', ['email' => $record->email]))
                ->schema([
                    PasswordInput::make('password')
                        ->label('New password')
                        ->required()
                        ->rule(Password::min(10)->letters()->numbers()),
                    Toggle::make('send_login_email')
                        ->label('Email the login details')
                        ->helperText(__('Sends the Client Area Login Details email with the password. Its switch under Settings → Email templates sets whether this starts ticked.'))
                        ->default(fn () => MailTemplates::enabled(MailTemplate::ClientAreaLogin)),
                ])
                ->modalSubmitActionLabel(__('Set password'))
                ->hidden(fn (Client $record) => ClientAreaAccess::isLinkedAdmin($record) || ClientAreaAccess::adminWithEmail($record))
                ->disabled(fn (Client $record) => blank($record->email))
                ->action(function (Client $record, array $data) {
                    try {
                        $sent = app(ClientAreaAccess::class)->setPassword($record, $data['password'], (bool) ($data['send_login_email'] ?? false));
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Password not set'))->body($e->getMessage())->send();

                        return;
                    }

                    $notification = Notification::make()->success()->title(__('Password set'));

                    if ($data['send_login_email'] ?? false) {
                        $sent
                            ? $notification->body(__('The login details were emailed to :email.', ['email' => $record->email]))
                            : $notification->warning()->body(__('The email could not be sent. See the email log.'));
                    }

                    $notification->send();
                }),
            Action::make('sendPasswordResetLink')
                ->label('Send password reset link')
                ->icon(Heroicon::OutlinedLink)
                ->requiresConfirmation()
                ->modalDescription(fn (Client $record) => __('Emails :email a link to choose a new password, the same one as "Forgot password?" on the login. Their current password keeps working until they do.', ['email' => $record->email]))
                ->visible(fn (Client $record) => $record->user?->active === true && ! ClientAreaAccess::isLinkedAdmin($record))
                ->action(function (Client $record) {
                    try {
                        app(ClientAreaAccess::class)->sendPasswordResetLink($record);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Not sent'))->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Password reset link sent to :email', ['email' => $record->email]))->send();
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
                ->visible(fn (Client $record) => $record->user?->active === false && ! ClientAreaAccess::isLinkedAdmin($record))
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
}
