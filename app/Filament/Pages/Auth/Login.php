<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Concerns\BlocksBannedIps;
use App\Filament\Concerns\HasCaptcha;
use App\Support\Demo;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;

/** The admin login, with the captcha Settings → General picks when "Admin login" is ticked there. */
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
            ...$this->captchaComponents('admin_login'),
        ]);
    }

    public function mount(): void
    {
        parent::mount();

        // The public demo: its login filled in (App\Support\Demo)
        if (Demo::enabled()) {
            $this->form->fill(['email' => Demo::ADMIN_EMAIL, 'password' => Demo::PASSWORD, 'remember' => true]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        return $this->withCaptcha(fn () => $this->countingFailedLogins('admin', fn () => parent::authenticate()));
    }
}
