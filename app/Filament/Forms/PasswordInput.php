<?php

namespace App\Filament\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/** A revealable password field with a "Generate" button that fills in a strong password. */
class PasswordInput
{
    public static function make(string $name = 'password'): TextInput
    {
        return TextInput::make($name)
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->maxLength(255)
            ->suffixAction(Action::make('generatePassword')
                ->label('Generate')
                ->tooltip(__('Generate a strong password'))
                ->icon(Heroicon::OutlinedSparkles)
                ->action(fn (Set $set) => $set($name, self::generate())));
    }

    public static function generate(): string
    {
        return Str::password(16, symbols: false);
    }
}
