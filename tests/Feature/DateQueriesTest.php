<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DateQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('date_query_rows', function (Blueprint $table) {
            $table->id();
            $table->date('day')->nullable();
            $table->dateTime('at')->nullable();
        });

        // Dates as Eloquent and the query builder store them (with and without a time), and datetimes through the day
        foreach (['2026-11-03', '2026-11-04', '2026-11-04 00:00:00', '2026-11-05'] as $day) {
            DB::table('date_query_rows')->insert(['day' => $day]);
        }

        foreach (['2026-11-03 23:59:59', '2026-11-04 00:00:00', '2026-11-04 13:30:00', '2026-11-04 23:59:59', '2026-11-05 00:00:00'] as $at) {
            DB::table('date_query_rows')->insert(['at' => $at]);
        }
    }

    public static function cases(): array
    {
        return collect(['day', 'at'])
            ->crossJoin(['=', '<', '<=', '>', '>='])
            ->mapWithKeys(fn (array $case) => ["{$case[0]} {$case[1]}" => $case])
            ->all();
    }

    #[DataProvider('cases')]
    public function test_it_finds_the_same_rows_as_where_date(string $column, string $operator): void
    {
        foreach (['2026-11-04', CarbonImmutable::parse('2026-11-04 15:00:00')] as $value) {
            $expected = DB::table('date_query_rows')->whereNotNull($column)->whereDate($column, $operator, $value)->pluck('id')->all();
            $actual = DB::table('date_query_rows')->whereNotNull($column)->whereOnDate($column, $operator, $value)->pluck('id')->all();

            $this->assertNotEmpty($expected);
            $this->assertSame($expected, $actual, "{$column} {$operator} {$value}");
        }
    }

    public function test_it_uses_a_plain_range_and_takes_the_short_form_and_or(): void
    {
        $query = DB::table('date_query_rows')->whereOnDate('day', '2026-11-04');

        $this->assertStringNotContainsString('strftime', $query->toSql());
        $this->assertSame(['2026-11-04', '2026-11-05'], $query->getBindings());
        $this->assertCount(2, $query->get());

        $either = DB::table('date_query_rows')->where(fn ($q) => $q->whereNull('day')->orWhereOnDate('day', '<', '2026-11-04'));

        $this->assertCount(6, $either->get()); // five datetime rows without a day, and the 3rd
    }
}
