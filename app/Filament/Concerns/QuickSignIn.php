<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\SignIn\EmailLinks;
use App\SignIn\Passkeys\PasskeyException;
use App\SignIn\Passkeys\Passkeys;
use App\SignIn\SignIn;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;

/**
 * The ways in besides the password, on both login pages (App\SignIn\SignIn): the sign-in plugins' buttons (Google),
 * "Log in with a passkey" and "Email me a sign-in link", each marked "Last used" when this browser last came in that
 * way. A sign-in that proved only the email address (Google, a link) comes back here pending: the two-factor code is
 * asked when the login has it on, and a link waits for Log in to be pressed. Used with BlocksBannedIps.
 */
trait QuickSignIn
{
    /** The login a link or Google is about to log in, while Log in is waiting to be pressed. */
    public ?string $pendingEmail = null;

    /** @return list<Htmlable> the sign-in plugins' buttons for this login */
    abstract protected function signInButtons(): array;

    protected function panelId(): string
    {
        return Filament::getCurrentOrDefaultPanel()->getId();
    }

    public function content(Schema $schema): Schema
    {
        $waiting = fn (): bool => filled($this->userUndertakingMultiFactorAuthentication) || filled($this->pendingEmail);

        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent()->visible(fn (): bool => ! $waiting()),
                $this->getMultiFactorChallengeFormContentComponent(),
                View::make('filament.sign-in.pending')
                    ->viewData(fn (): array => ['email' => $this->pendingEmail])
                    ->visible(fn (): bool => filled($this->pendingEmail) && blank($this->userUndertakingMultiFactorAuthentication)),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
                View::make('filament.sign-in.options')
                    ->viewData(fn (): array => [
                        'buttons' => $this->signInButtons(),
                        'passkeys' => SignIn::passkeys($this->panelId()),
                        'emailLink' => SignIn::emailLinks($this->panelId()),
                        'lastUsed' => SignIn::lastUsed($this->panelId()),
                    ])
                    ->visible(fn (): bool => ! $waiting()),
            ]);
    }

    /** From mount(): a pending sign-in asks for the two-factor code, or for Log in to be pressed. */
    protected function startPendingSignIn(): void
    {
        $pending = SignIn::pending($this->panelId());

        if ($pending === null) {
            return;
        }

        $user = $pending['user'];

        if (! SignIn::needsTwoFactor($user, $this->panelId())) {
            $this->pendingEmail = $user->email;

            return;
        }

        $challenge = $this->getMultiFactorChallenge();
        $this->userUndertakingMultiFactorAuthentication = encrypt([
            'identifier' => $user->getAuthIdentifier(),
            'userKey' => Filament::getUserScopedAuthIdentifier($user),
        ]);
        $challenge->beforeChallenge($user, $challenge->getFirstEnabledProvider($user));
        $this->multiFactorChallengeForm->fill();
    }

    /**
     * The pending sign-in's Log in (or two-factor code) pressed: null when it still waits.
     *
     * @param  array{user: User, method: string, nonce?: string}  $pending
     */
    protected function finishPendingSignIn(array $pending): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $user = $pending['user'];

        if (SignIn::needsTwoFactor($user, $this->panelId())) {
            if (blank($this->userUndertakingMultiFactorAuthentication)) {
                $this->startPendingSignIn();

                return null;
            }

            if ($this->isMultiFactorChallengeRateLimited($user)) {
                return null;
            }

            $this->cacheSchema('multiFactorChallengeForm', null);
            $this->multiFactorChallengeForm->validate();
        }

        if (isset($pending['nonce']) && ! EmailLinks::use($pending['nonce'])) {
            $this->cancelPendingSignIn();
            Notification::make()->title(__('This sign-in link has expired or was already used. Ask for a new one.'))->danger()->persistent()->send();

            return null;
        }

        if ($pending['method'] === SignIn::EMAIL_LINK && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // the link reached them
        }

        SignIn::complete($user, $this->panelId(), $pending['method']);

        return app(LoginResponse::class);
    }

    /** "Use another account" while a sign-in is pending. */
    public function cancelPendingSignIn(): void
    {
        SignIn::forget();
        $this->pendingEmail = null;
        $this->userUndertakingMultiFactorAuthentication = null;
        $this->form->fill();
    }

    /** @return array<string, mixed> what the browser needs to ask for a passkey */
    public function passkeyOptions(): array
    {
        abort_unless(SignIn::passkeys($this->panelId()), 404);

        return app(Passkeys::class)->requestOptions();
    }

    /** @param  array<string, mixed>  $credential  the browser's answer to passkeyOptions() */
    public function passkeyLogin(array $credential): void
    {
        abort_unless(SignIn::passkeys($this->panelId()), 404);

        if ($this->redirectIfBanned(filament()->getLoginUrl())) {
            return;
        }

        try {
            $this->rateLimit(10);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        try {
            $user = app(Passkeys::class)->verify($credential)->user;
        } catch (PasskeyException $e) {
            Log::info('Passkey login refused: '.$e->getMessage());
            Notification::make()->title(__('This passkey could not be used here. Try again, or log in with your password.'))->danger()->send();

            return;
        }

        if (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Notification::make()->title(__('This passkey\'s login can\'t log in here.'))->danger()->send();

            return;
        }

        SignIn::complete($user, $this->panelId(), SignIn::PASSKEY);
        $this->redirect(session()->pull('url.intended', Filament::getUrl()));
    }

    /** A passkey that the browser could not give (cancelled, none for this site). */
    public function passkeyFailed(string $reason): void
    {
        if ($reason !== 'NotAllowedError') { // pressing Cancel needs no message
            Notification::make()->title(__('This passkey could not be used here. Try again, or log in with your password.'))->danger()->send();
        }
    }

    public function emailLinkAction(): Action
    {
        return Action::make('emailLink')
            ->label(__('Email me a sign-in link'))
            ->modalHeading(__('Log in with a link by email'))
            ->modalDescription(__('We email you a link that logs you in without a password. It works once, for :count minutes.', ['count' => EmailLinks::MINUTES]))
            ->modalWidth('md')
            ->fillForm(fn (): array => ['email' => $this->data['email'] ?? null])
            ->schema([
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->autocomplete('email'),
            ])
            ->modalSubmitActionLabel(__('Send the link'))
            ->visible(fn (): bool => SignIn::emailLinks($this->panelId()))
            ->action(function (array $data): void {
                if ($this->redirectIfBanned(filament()->getLoginUrl())) {
                    return;
                }

                app(EmailLinks::class)->send((string) $data['email'], $this->panelId());

                Notification::make()
                    ->title(__('Check your email'))
                    ->body(__('If :email can log in here, a sign-in link is on its way.', ['email' => $data['email']]))
                    ->success()
                    ->send();
            });
    }
}
