<?php

namespace App\Filament\Actions;

use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** A mass action with no form: confirm, then $apply on each picked record $skip gives no reason for, one summary. */
class SimpleBulkAction
{
    /**
     * @param  callable(mixed): ?string  $skip  why a record is left out (a translated text), or null
     * @param  callable(mixed): mixed  $apply
     * @param  callable(mixed): string  $nameOf  the record's name in the summary
     * @param  string  $done  e.g. ':count product hidden|:count products hidden'
     * @param  string|null  $text  what it does, after "Applies to … of the … picked."
     */
    public static function make(string $name, string $label, Heroicon $icon, callable $skip, callable $apply, callable $nameOf, string $done, ?string $text = null, string $color = 'gray'): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading(__($label))
            ->modalDescription(fn (Collection $records) => trim(BulkOutcome::preview($records, $skip).' '.$text))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => BulkOutcome::run($records, $skip, $apply, $nameOf)->notify($done));
    }
}
