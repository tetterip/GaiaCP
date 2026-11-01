<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use App\Filament\Concerns\QuickSignIn;
use App\Plugins\PluginManager;
use App\SignIn\Passkeys\Passkeys;
use App\SignIn\SignIn;
use App\Support\Demo;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;

/**
 * The admin login, with the captcha Settings → General picks when "Admin login" is ticked there, and the other ways in
 * (QuickSignIn): passkeys, a link by email and the sign-in plugins (Google).
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
            ...$this->captchaComponents('admin_login'),
        ]);
    }

    public function mount(): void
    {
        parent::mount();
        $this->startPendingSignIn();

        // The public demo: its login filled in (App\Support\Demo)
        if (Demo::enabled()) {
            $this->form->fill(['email' => Demo::ADMIN_EMAIL, 'password' => Demo::PASSWORD, 'remember' => true]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        if (($pending = SignIn::pending('admin')) !== null) {
            return $this->countingFailedLogins('admin', fn () => $this->finishPendingSignIn($pending));
        }

        $response = $this->withCaptcha(fn () => $this->countingFailedLogins('admin', fn () => parent::authenticate()));

        if ($response !== null) {
            SignIn::remember('admin', SignIn::PASSWORD);
            Passkeys::confirm(Filament::auth()->user());
        }

        return $response;
    }

    protected function signInButtons(): array
    {
        return app(PluginManager::class)->adminLoginButtons();
    }
}
