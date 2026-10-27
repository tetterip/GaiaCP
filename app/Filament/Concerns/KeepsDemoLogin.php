<?php

namespace App\Filament\Concerns;

use App\Support\Demo;
use Filament\Schemas\Components\Component;

/**
 * A profile page in the public demo (App\Support\Demo): the email can't be changed and the password fields are gone,
 * so nobody locks the next visitor out of the demo accounts. Demo::boot() keeps them as they are anyway.
 */
trait KeepsDemoLogin
{
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->disabled(Demo::enabled());
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->hidden(Demo::enabled());
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()->hidden(Demo::enabled());
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()->hidden(Demo::enabled());
    }
}
