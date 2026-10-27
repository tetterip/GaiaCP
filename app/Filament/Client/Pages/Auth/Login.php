<?php

namespace App\Filament\Client\Pages\Auth;

use App\Actions\ClientSignup;
use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use App\Support\Demo;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The client area's login: the "Sign up" link shows only while Settings → Client area lets new clients sign up, and
 * the captcha Settings → General picks, when it is on this form.
 */
class Login extends BaseLogin
{
    use BlocksBannedIps;
    use HasCaptcha;

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

        // The public demo: its login filled in (App\Support\Demo)
        if (Demo::enabled()) {
            $this->form->fill(['email' => Demo::CLIENT_EMAIL, 'password' => Demo::PASSWORD, 'remember' => true]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        return $this->withCaptcha(fn () => $this->countingFailedLogins('client', fn () => parent::authenticate()));
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! ClientSignup::enabled() && blank($this->userUndertakingMultiFactorAuthentication)) {
            return null;
        }

        return parent::getSubheading();
    }
}
