<?php

namespace AgenticActions\Datasets;

use LogicException;

/**
 * The SQL that puts a stored time in its bucket: the time moved into the dataset's zone by the minutes of its segment,
 * then cut to the first day of its day, week (from Monday), month, quarter or year, per driver, with no time zone
 * tables.
 *
 * @internal
 */
final class Buckets
{
    /**
     * Each driver's cut of a moved time {t} to its grain's first day, as a date.
     */
    private const CUTS = [
        'sqlite' => [
            'day' => 'date({t})',
            'week' => "date({t}, 'weekday 0', '-6 days')",
            'month' => "date({t}, 'start of month')",
            'quarter' => "date({t}, 'start of month', '-' || ((cast(strftime('%m', {t}) as integer) - 1) % 3) || ' months')",
            'year' => "date({t}, 'start of year')",
        ],
        'mysql' => [
            'day' => 'date({t})',
            'week' => 'date({t}) - interval weekday({t}) day',
            'month' => 'makedate(year({t}), 1) + interval (month({t}) - 1) month',
            'quarter' => 'makedate(year({t}), 1) + interval (quarter({t}) - 1) quarter',
            'year' => 'makedate(year({t}), 1)',
        ],
        'pgsql' => [
            'day' => "cast(date_trunc('day', {t}) as date)",
            'week' => "cast(date_trunc('week', {t}) as date)",
            'month' => "cast(date_trunc('month', {t}) as date)",
            'quarter' => "cast(date_trunc('quarter', {t}) as date)",
            'year' => "cast(date_trunc('year', {t}) as date)",
        ],
    ];

    /**
     * The bucket of a wrapped time column, and its bindings: each segment's start and minutes. The moved time is
     * written once per use in the cut, each with its own bindings.
     *
     * @param  list<array{0: string, 1: int}>  $segments  from Range::segments(), the first one the range's start
     * @return array{0: string, 1: list<string|int>}
     *
     * @throws LogicException for a driver datasets do not support
     */
    public static function expression(string $driver, string $grain, string $column, array $segments): array
    {
        $cuts = self::CUTS[$driver === 'mariadb' ? 'mysql' : $driver] ?? throw new LogicException("Datasets do not support the [{$driver}] database driver: use SQLite, MySQL, MariaDB or Postgres.");
        $minutes = $driver === 'pgsql' ? 'cast(? as integer)' : '?';

        [$shift, $bindings] = self::shift($segments, $column, $minutes);

        $moved = match ($driver) {
            'sqlite' => "datetime({$column}, ({$shift}) || ' minutes')",
            'pgsql' => "({$column} + make_interval(mins => {$shift}))",
            default => "({$column} + interval ({$shift}) minute)",
        };

        $cut = $cuts[$grain];

        return [str_replace('{t}', $moved, $cut), array_merge(...array_fill(0, substr_count($cut, '{t}'), $bindings))];
    }

    /**
     * The minutes of the segment a time falls in, as CASEs that halve the segments at each step, so a row reads about
     * log2 of them however long the range: a range of centuries crosses hundreds of offset changes.
     *
     * @param  non-empty-list<array{0: string, 1: int}>  $segments
     * @return array{0: string, 1: list<string|int>}
     */
    private static function shift(array $segments, string $column, string $minutes): array
    {
        if (count($segments) === 1) {
            return [$minutes, [$segments[0][1]]];
        }

        $middle = intdiv(count($segments), 2);
        [$high, $highBindings] = self::shift(array_slice($segments, $middle), $column, $minutes);
        [$low, $lowBindings] = self::shift(array_slice($segments, 0, $middle), $column, $minutes);

        return ["case when {$column} >= ? then {$high} else {$low} end", [$segments[$middle][0], ...$highBindings, ...$lowBindings]];
    }
}
