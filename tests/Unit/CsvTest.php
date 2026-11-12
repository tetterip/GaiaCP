<?php

namespace Tests\Unit;

use App\Reports\Csv;
use PHPUnit\Framework\TestCase;

/** A CSV cell that a spreadsheet would run as a formula is kept as text. */
class CsvTest extends TestCase
{
    public function test_a_text_starting_like_a_formula_gets_a_quote(): void
    {
        foreach (['=HYPERLINK("http://x","y")', '+1+2', '-2+3', '-1,5+A1', '@SUM(A1)', "\tx", "\rx"] as $text) {
            $this->assertSame("'".$text, Csv::cell($text), $text);
        }
    }

    public function test_numbers_and_plain_text_stay(): void
    {
        foreach (['-12.50', '12.50', '+50%', '-3.5%', 'Μαρία Παπαδοπούλου', 'a=b', '', null, -1250, 3.5, true] as $value) {
            $this->assertSame($value, Csv::cell($value));
        }
    }
}
