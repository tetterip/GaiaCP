<?php

namespace App\Filament\Client\Pages\Auth;

use App\Actions\ClientSignup;
use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use App\Filament\Concerns\QuickSignIn;
use App\Plugins\PluginManager;
use App\SignIn\SignIn;
use App\Support\Demo;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The client area's login: the "Sign up" link shows only while Settings → Client area lets new clients sign up, and
 * the captcha Settings → General picks, when it is on this form. The other ways in (QuickSignIn): passkeys, a link by
 * email and the sign-in plugins (Google).
 */
class Login extends BaseLogin
{
    use BlocksBannedIps;
    use HasCaptcha;
    use QuickSignIn;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
            ...$this->captchaComponents('client_login'),
        ]);
    }

    public function mount(): void
    {
        parent::mount();
        $this->startPendingSignIn();

        // The public demo: its login filled in (App\Support\Demo)
        if (Demo::enabled()) {
            $this->form->fill(['email' => Demo::CLIENT_EMAIL, 'password' => Demo::PASSWORD, 'remember' => true]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        if (($pending = SignIn::pending('client')) !== null) {
            return $this->countingFailedLogins('client', fn () => $this->finishPendingSignIn($pending));
        }

        $response = $this->withCaptcha(fn () => $this->countingFailedLogins('client', fn () => parent::authenticate()));

        if ($response !== null) {
            SignIn::remember('client', SignIn::PASSWORD);
        }

        return $response;
    }

    protected function signInButtons(): array
    {
        return app(PluginManager::class)->clientLoginButtons();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->pendingEmail) || (! ClientSignup::enabled() && blank($this->userUndertakingMultiFactorAuthentication))) {
            return null;
        }

        return parent::getSubheading();
    }
}
