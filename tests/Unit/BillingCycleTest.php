<?php

namespace Tests\Unit;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    /** @return array<string, array{BillingCycle, string, string}> */
    public static function cycles(): array
    {
        return [
            'monthly' => [BillingCycle::Monthly, '2026-03-15', '2026-04-15'],
            'quarterly' => [BillingCycle::Quarterly, '2026-03-15', '2026-06-15'],
            'semiannually' => [BillingCycle::Semiannually, '2026-03-15', '2026-09-15'],
            'annually' => [BillingCycle::Annually, '2026-03-15', '2027-03-15'],
            'biennially' => [BillingCycle::Biennially, '2026-03-15', '2028-03-15'],
            'triennially' => [BillingCycle::Triennially, '2026-03-15', '2029-03-15'],
        ];
    }

    #[DataProvider('cycles')]
    public function test_advances_by_one_cycle(BillingCycle $cycle, string $from, string $expected): void
    {
        $this->assertSame($expected, $cycle->advance(CarbonImmutable::parse($from))->toDateString());
    }

    public function test_month_end_is_clamped_not_overflowed(): void
    {
        $this->assertSame('2026-02-28', BillingCycle::Monthly->advance(CarbonImmutable::parse('2026-01-31'))->toDateString());
        $this->assertSame('2029-02-28', BillingCycle::Annually->advance(CarbonImmutable::parse('2028-02-29'))->toDateString());
    }

    public function test_anchor_day_prevents_drift_after_a_short_month(): void
    {
        $feb = BillingCycle::Monthly->advance(CarbonImmutable::parse('2026-01-31'), 31);
        $mar = BillingCycle::Monthly->advance($feb, 31);
        $apr = BillingCycle::Monthly->advance($mar, 31);

        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], [$feb->toDateString(), $mar->toDateString(), $apr->toDateString()]);
    }

    public function test_leap_day_anniversary_returns_to_the_29th(): void
    {
        $next = BillingCycle::Annually->advance(CarbonImmutable::parse('2028-02-29'), 29);
        $this->assertSame('2029-02-28', $next->toDateString());

        $this->assertSame('2032-02-29', BillingCycle::Annually->advance(CarbonImmutable::parse('2031-02-28'), 29)->toDateString());
    }
}
