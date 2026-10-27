<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string|int|float, int}> */
    public static function amounts(): array
    {
        return [
            'dot decimal' => ['12.50', 1250],
            'comma decimal' => ['12,5', 1250],
            'whole' => ['60', 6000],
            'int' => [60, 6000],
            'float' => [19.99, 1999],
            'cents only' => ['0.07', 7],
            'padded' => [' 1 200.00 ', 120000],
        ];
    }

    #[DataProvider('amounts')]
    public function test_to_cents(string|int|float $input, int $expected): void
    {
        $this->assertSame($expected, Money::toCents($input));
    }

    public function test_rejects_garbage_and_sub_cent_precision(): void
    {
        foreach (['abc', '1.234', '-5', ''] as $bad) {
            try {
                Money::toCents($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_to_decimal(): void
    {
        $this->assertSame('12.50', Money::toDecimal(1250));
        $this->assertSame('0.07', Money::toDecimal(7));
    }
}
