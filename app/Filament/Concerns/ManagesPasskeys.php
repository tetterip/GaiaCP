<?php

namespace App\Filament\Concerns;

use App\Actions\ClientAreaAccess;
use App\ClientSessions\ClientSessions;
use App\Models\ActivityLog;
use App\Models\User;
use App\SignIn\Passkeys\PasskeyException;
use App\SignIn\Passkeys\Passkeys;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Log;

/**
 * Profile → Passkeys on both panels (App\SignIn\Passkeys): add one on this device, see and remove the others. Not
 * while an admin looks through a client's login (their device's passkey would open the client's account).
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

    /** @return array<string, mixed> what the browser needs to make a passkey */
    public function passkeyCreationOptions(): array
    {
        abort_unless($this->managesPasskeys(), 404);

        /** @var User $user */
        $user = $this->getUser();

        return app(Passkeys::class)->creationOptions($user);
    }

    /** @param  array<string, mixed>  $credential  the browser's answer to passkeyCreationOptions() */
    public function addPasskey(array $credential): void
    {
        abort_unless($this->managesPasskeys(), 404);

        /** @var User $user */
        $user = $this->getUser();

        try {
            $passkey = app(Passkeys::class)->register($user, $credential, ClientSessions::device(request()->userAgent()));
        } catch (PasskeyException $e) {
            Log::info('Passkey not added: '.$e->getMessage());
            $this->passkeyNotAdded();

            return;
        }

        ActivityLog::record('sign_in.passkey_added', "Passkey \"{$passkey->name}\" added for {$user->email}", client: $user->client);
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
