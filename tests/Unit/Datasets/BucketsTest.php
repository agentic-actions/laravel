<?php

use AgenticActions\Datasets\Buckets;
use Illuminate\Support\Facades\DB;

/*
 * A bucket is written for the drivers datasets support, and refused, by name, for any other. A time is moved into the
 * dataset's zone by the offset of the segment it falls in, found in about log2 of the segments: a range of centuries
 * crosses hundreds of offset changes, and one CASE per change made every row read them all.
 */

it('refuses a driver datasets do not support, naming it', function () {
    expect(fn () => Buckets::expression('sqlsrv', 'day', '[posts].[created_at]', [['2026-09-01 00:00:00', 0]]))
        ->toThrow(LogicException::class, 'Datasets do not support the [sqlsrv] database driver: use SQLite, MySQL, MariaDB or Postgres.');
});

it('finds the offset of a time in about log2 of the offset changes, however many the range crosses', function () {
    $segments = array_map(fn (int $index): array => [date('Y-m-d H:i:s', 946684800 + $index * 3600), 60 * ($index % 3)], range(0, 999));
    [$sql, $bindings] = Buckets::expression('sqlite', 'day', 't', $segments);

    [$depth, $deepest] = [0, 0];

    foreach (preg_split('/\b(case|end)\b/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
        $depth += ['case' => 1, 'end' => -1][$token] ?? 0;
        $deepest = max($deepest, $depth);
    }

    // Each CASE asks once, so a row asks as many times as CASEs are nested.
    expect(substr_count($sql, ' when '))->toBe(substr_count($sql, 'case '))
        ->and(substr_count($sql, ' when '))->toBe(999)
        ->and($deepest)->toBe(10)
        ->and($bindings)->toHaveCount(999 + 1000);
});

it('gives each time the offset of the segment it falls in', function (int $index, int $seconds) {
    $segments = array_map(fn (int $at): array => [date('Y-m-d H:i:s', 946684800 + $at * 86400), 60 * 24 * ($at % 3)], range(0, 99));
    [$sql, $bindings] = Buckets::expression('sqlite', 'day', 't', $segments);
    $time = date('Y-m-d H:i:s', 946684800 + $index * 86400 + $seconds);

    $day = DB::connection()->selectOne("select {$sql} as day from (select ? as t)", [...$bindings, $time])->day;

    expect($day)->toBe(date('Y-m-d', strtotime($time.' UTC') + 60 * 60 * 24 * ($index % 3)));
})->with([
    'before the first change' => [0, 3600],
    'on a change' => [1, 0],
    'just after one' => [49, 60],
    'on the middle one' => [50, 0],
    'just before one' => [98, 86399],
    'on the last one' => [99, 0],
]);
