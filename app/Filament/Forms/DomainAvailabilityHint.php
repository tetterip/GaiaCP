<?php

namespace App\Filament\Forms;

use App\Enums\DomainAvailability;
use App\Support\DomainSearch;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Whether the domain typed in an order form is free (App\Support\DomainSearch), shown by the field once it is left:
 * "Available" / "Taken" / "Probably available" when registering, a warning when a transfer's domain isn't registered.
 */
class DomainAvailabilityHint
{
    /**
     * @param  Closure(Get): ?string  $kind  "register", "transfer" or null (nothing to check)
     * @param  bool  $limited  false for admins: their lookups aren't rate limited
     */
    public static function on(TextInput $field, Closure $kind, bool $limited = true): TextInput
    {
        $found = function (Get $get, ?string $state) use ($kind, $limited): ?array {
            $what = $kind($get);
            $availability = in_array($what, ['register', 'transfer'], true) ? DomainSearch::forOrder($state, $limited) : null;

            return $availability ? [$availability, $what === 'transfer'] : null;
        };

        return $field
            ->hint(fn (Get $get, ?string $state) => self::with($found($get, $state), fn (DomainAvailability $found, bool $transfer) => $found->orderHint($transfer)))
            ->hintIcon(fn (Get $get, ?string $state) => self::with($found($get, $state), fn (DomainAvailability $found, bool $transfer) => $found->orderHint($transfer) !== null ? $found->orderHintIcon($transfer) : null))
            ->hintColor(fn (Get $get, ?string $state) => self::with($found($get, $state), fn (DomainAvailability $found, bool $transfer) => $found->orderHintColor($transfer)) ?? 'gray');
    }

    /** @param  array{0: DomainAvailability, 1: bool}|null  $found */
    private static function with(?array $found, Closure $then): mixed
    {
        return $found === null ? null : $then(...$found);
    }
}
