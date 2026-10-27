<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a custom client field does in the client area: hidden (admins only), shown read-only, or filled in by the client. */
enum ClientFieldAccess: string implements HasLabel
{
    case Hidden = 'hidden';
    case Shown = 'shown';
    case Editable = 'editable';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Hidden => 'Hidden: only admins see it',
            self::Shown => 'Shown: clients see it, only admins change it',
            self::Editable => 'Editable: clients fill it in at sign up and in their profile',
        });
    }
}
