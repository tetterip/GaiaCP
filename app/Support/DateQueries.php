<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * whereOnDate() / orWhereOnDate(): whereDate() as a plain range on the column. whereDate() wraps the column in a
 * function (date(), strftime()), so MySQL / MariaDB can't use its index and reads every row; "due before today" on
 * a large notices or services table then gets slower with every year of history. Same arguments and same results
 * as whereDate() (whole days, in the app's timezone), on date and datetime columns alike:
 *
 *   = d    →  col >= d and col < d + 1
 *   <  d   →  col < d
 *   <= d   →  col < d + 1        (a datetime later that day, or a date stored with a time, still counts)
 *   >  d   →  col >= d + 1
 *   >= d   →  col >= d
 *
 * Registered on the query builder, so Eloquent builders and relations have it too.
 */
class DateQueries
{
    public static function register(): void
    {
        Builder::macro('whereOnDate', function (string $column, mixed $operator, mixed $value = null, string $boolean = 'and') {
            /** @var Builder $this */
            [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

            return DateQueries::apply($this, $column, (string) $operator, $value, $boolean);
        });

        Builder::macro('orWhereOnDate', function (string $column, mixed $operator, mixed $value = null) {
            /** @var Builder $this */
            [$operator, $value] = func_num_args() === 2 ? ['=', $operator] : [$operator, $value];

            return DateQueries::apply($this, $column, (string) $operator, $value, 'or');
        });
    }

    public static function apply(Builder $query, string $column, string $operator, mixed $value, string $boolean = 'and'): Builder
    {
        $day = ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value))->startOfDay();
        $date = $day->toDateString();
        $next = $day->addDay()->toDateString();

        return match ($operator) {
            '=' => $query->where(fn (Builder $q) => $q->where($column, '>=', $date)->where($column, '<', $next), boolean: $boolean),
            '<' => $query->where($column, '<', $date, $boolean),
            '<=' => $query->where($column, '<', $next, $boolean),
            '>' => $query->where($column, '>=', $next, $boolean),
            '>=' => $query->where($column, '>=', $date, $boolean),
            default => throw new InvalidArgumentException("whereOnDate() does not take the operator [{$operator}]."),
        };
    }
}
