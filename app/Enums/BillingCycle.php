<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum BillingCycle: string implements HasLabel
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannually = 'semiannually';
    case Annually = 'annually';
    case Biennially = 'biennially';
    case Triennially = 'triennially';
    // Billed once and never renewed (work done once, e.g. moving a site): only for other services. Paid, it is Completed.
    case OneTime = 'onetime';

    /** Months in one cycle; 0 for a one-time service. */
    public function months(): int
    {
        return match ($this) {
            self::OneTime => 0,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannually => 6,
            self::Annually => 12,
            self::Biennially => 24,
            self::Triennially => 36,
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /**
     * Every cycle that renews (all but One time).
     *
     * @return list<self>
     */
    public static function recurring(): array
    {
        return array_values(array_filter(self::cases(), fn (self $cycle) => $cycle->isRecurring()));
    }

    /**
     * The cycles a service of $type can have: One time only for other services.
     *
     * @return array<string, string> value => label
     */
    public static function optionsFor(?ServiceType $type): array
    {
        return collect($type === ServiceType::Other ? self::cases() : self::recurring())
            ->mapWithKeys(fn (self $cycle) => [$cycle->value => $cycle->getLabel()])->all();
    }

    /** The cycle of a term in whole years (a domain's): 1 → Annually, 2 → Biennially, 3 → Triennially. */
    public static function forYears(int $years): self
    {
        return match ($years) {
            2 => self::Biennially,
            3 => self::Triennially,
            default => self::Annually,
        };
    }

    public function getLabel(): string
    {
        return $this === self::OneTime ? __('One time') : __(ucfirst($this->value));
    }

    /**
     * The due date one cycle after $from. Month-end dates are clamped
     * (Jan 31 + 1 month = Feb 28/29), never rolled into the next month.
     *
     * $anchorDay is the service's original day of month: passing it stops a
     * clamped date from drifting (Jan 31 -> Feb 28 -> Mar 31, not Mar 28).
     * A one-time service never moves on: its date stays where it is.
     */
    public function advance(CarbonInterface $from, ?int $anchorDay = null): CarbonImmutable
    {
        if ($this === self::OneTime) {
            return CarbonImmutable::parse($from)->startOfDay();
        }

        $next = CarbonImmutable::parse($from)->startOfDay()->addMonthsNoOverflow($this->months());

        if ($anchorDay !== null) {
            $next = $next->setDay(min($anchorDay, $next->daysInMonth));
        }

        return $next;
    }
}
