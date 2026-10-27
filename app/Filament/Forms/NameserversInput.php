<?php

namespace App\Filament\Forms;

use App\Rules\ValidNameservers;
use App\Support\DomainName;
use Filament\Forms\Components\TagsInput;
use Illuminate\Validation\ValidationException;

/** Two to four nameservers, each a host name like ns1.example.com, saved lowercase without repeats. */
class NameserversInput
{
    public const MAX = 4;

    public static function make(string $name = 'nameservers'): TagsInput
    {
        return TagsInput::make($name)
            ->rules(['array', 'max:'.self::MAX, new ValidNameservers])
            ->validationMessages(['max' => __('At most 4 nameservers.')])
            ->placeholder('ns1.example.com')
            // Each one is checked as it is added, not only on save (the minimum of two only on save).
            ->live()
            ->afterStateUpdated(fn (TagsInput $component, ?array $state, $livewire) => self::check($component, $state ?? [], $livewire))
            ->dehydrateStateUsing(fn (?array $state) => DomainName::nameservers($state));
    }

    /** @param  array<int, mixed>  $state */
    private static function check(TagsInput $component, array $state, mixed $livewire): void
    {
        $bad = array_values(array_filter($state, fn ($ns) => filled($ns) && ! DomainName::isNameserver((string) $ns)));

        $message = match (true) {
            $bad !== [] => __('":nameserver" is not a nameserver. Enter names like ns1.example.com.', ['nameserver' => $bad[0]]),
            count($state) > self::MAX => __('At most 4 nameservers.'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages([$component->getStatePath() => $message]);
        }

        $livewire->resetErrorBag($component->getStatePath());
    }
}
