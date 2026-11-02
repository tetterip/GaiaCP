<?php

namespace App\Filament\Concerns;

use App\Actions\ClientAreaAccess;
use App\ClientSessions\ClientSessions;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\PasskeyAdded;
use App\SignIn\Passkeys\PasskeyException;
use App\SignIn\Passkeys\Passkeys;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Profile → Passkeys on both panels (App\SignIn\Passkeys): add one on this device, see and remove the others. Not
 * while an admin looks through a client's login (their device's passkey would open the client's account).
 * Adding one asks for the password unless the login signed in within Passkeys::CONFIRM_SECONDS, and emails the
 * login, so a browser left signed in can't quietly get a way back in.
 */
trait ManagesPasskeys
{
    protected function getPasskeysFormComponent(): Component
    {
        return View::make('filament.passkeys')
            ->viewData(fn (): array => ['passkeys' => $this->getUser()->passkeys()->latest()->get()])
            ->visible(fn (): bool => $this->managesPasskeys());
    }

    private function managesPasskeys(): bool
    {
        return Passkeys::enabled() && ! session()->has(ClientAreaAccess::VIEWING_AS);
    }

    /** @return array<string, mixed> what the browser needs to make a passkey, or ['confirm' => true] to ask for the password first */
    public function passkeyCreationOptions(): array
    {
        abort_unless($this->managesPasskeys(), 404);

        /** @var User $user */
        $user = $this->getUser();

        if (! Passkeys::confirmed($user)) {
            $this->mountAction('confirmPasskey');

            return ['confirm' => true];
        }

        return app(Passkeys::class)->creationOptions($user);
    }

    /** "Confirm it's you" before a passkey is added: the login's password, then on to the device's prompt. */
    public function confirmPasskeyAction(): Action
    {
        return Action::make('confirmPasskey')
            ->label('Confirm it\'s you')
            ->modalHeading(__('Confirm it\'s you'))
            ->modalDescription(__('Type your password to add a passkey. No password? Log out and log in again, then add it within 10 minutes.'))
            ->modalWidth('md')
            ->schema([
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('current-password'),
            ])
            ->modalSubmitActionLabel(__('Continue'))
            ->action(function (array $data, Action $action): void {
                abort_unless($this->managesPasskeys(), 404);

                /** @var User $user */
                $user = $this->getUser();
                $key = 'passkey-confirm:'.$user->getKey();

                if (RateLimiter::tooManyAttempts($key, 5)) {
                    Notification::make()->title(__('Too many tries. Please wait a few minutes.'))->danger()->send();
                    $action->halt();
                }

                if (! Hash::check((string) $data['password'], (string) $user->getAuthPassword())) {
                    RateLimiter::hit($key, 300);
                    Notification::make()->title(__('That password is not right.'))->danger()->send();
                    $action->halt();
                }

                RateLimiter::clear($key);
                Passkeys::confirm($user);
                Notification::make()->title(__('Thanks. If your device does not ask for the passkey now, press ":button" again.', ['button' => __('Add a passkey on this device')]))->success()->send();
                $this->dispatch('passkey-confirmed');
            });
    }

    /** @param  array<string, mixed>  $credential  the browser's answer to passkeyCreationOptions() */
    public function addPasskey(array $credential): void
    {
        abort_unless($this->managesPasskeys(), 404);

        /** @var User $user */
        $user = $this->getUser();

        if (! Passkeys::confirmed($user)) {
            $this->passkeyNotAdded();

            return;
        }

        try {
            $passkey = app(Passkeys::class)->register($user, $credential, ClientSessions::device(request()->userAgent()));
        } catch (PasskeyException $e) {
            Log::info('Passkey not added: '.$e->getMessage());
            $this->passkeyNotAdded();

            return;
        }

        ActivityLog::record('sign_in.passkey_added', "Passkey \"{$passkey->name}\" added for {$user->email}", client: $user->client);
        rescue(fn () => $user->notify(new PasskeyAdded($passkey, Filament::getCurrentOrDefaultPanel()->getId() === 'client')), report: true);
        Notification::make()->title(__('Passkey added. Next time, press "Log in with a passkey" on the login page.'))->success()->send();
    }

    public function passkeyNotAdded(?string $reason = null): void
    {
        if ($reason === 'InvalidStateError') { // this device already holds one of this login's passkeys
            Notification::make()->title(__('This device already has a passkey for you.'))->warning()->send();

            return;
        }

        if ($reason !== 'NotAllowedError') { // pressing Cancel needs no message
            Notification::make()->title(__('The passkey was not added. Please try again.'))->danger()->send();
        }
    }

    public function removePasskey(int $id): void
    {
        /** @var User $user */
        $user = $this->getUser();
        $passkey = $user->passkeys()->find($id);

        if ($passkey !== null) {
            $passkey->delete();
            ActivityLog::record('sign_in.passkey_removed', "Passkey \"{$passkey->name}\" removed for {$user->email}", client: $user->client);
        }
    }
}
