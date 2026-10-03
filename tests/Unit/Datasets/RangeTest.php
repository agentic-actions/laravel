<?php

use AgenticActions\Datasets\Range;
use Carbon\CarbonImmutable;

/*
 * A dataset call's days: bounds resolved in the dataset's zone, local midnights as instants, the previous period, the
 * buckets of a grain, and where the zone's offset changes within a range.
 */

/**
 * A range's first and last day, both included, as dates.
 *
 * @return array{0: string, 1: string}
 */
function rangeDays(Range $range): array
{
    return [$range->start->toDateString(), $range->end->subDay()->toDateString()];
}

it('resolves a relative bound to the first day of its unit, counting back from today in the dataset\'s zone', function (string $zone, array $dates) {
    // Wednesday 30 September in UTC is already Thursday 1 October in Tokyo.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC'));

    $bounds = ['today', '-1d', '-30d', '-1w', '-8w', '-1m', '-6m', '-1q', '-2q', '-1y', '-3y', '2026-02-15'];

    expect(array_combine($bounds, array_map(fn (string $bound): string => Range::date($bound, new DateTimeZone($zone)), $bounds)))->toBe(array_combine($bounds, $dates));
})->with([
    'UTC' => ['UTC', ['2026-09-30', '2026-09-30', '2026-09-01', '2026-09-28', '2026-08-10', '2026-09-01', '2026-04-01', '2026-07-01', '2026-04-01', '2026-01-01', '2024-01-01', '2026-02-15']],
    'Asia/Tokyo' => ['Asia/Tokyo', ['2026-10-01', '2026-10-01', '2026-09-02', '2026-09-28', '2026-08-10', '2026-10-01', '2026-05-01', '2026-10-01', '2026-07-01', '2026-01-01', '2024-01-01', '2026-02-15']],
]);

it('resolves a range from the local midnight of its first day to the one after its last, across an offset change', function () {
    // Berlin moves from UTC+1 to UTC+2 on 29 March 2026.
    $range = Range::resolve('2026-03-28', '2026-03-29', new DateTimeZone('Europe/Berlin'));

    expect($range->start->utc()->toDateTimeString())->toBe('2026-03-27 23:00:00')
        ->and($range->end->utc()->toDateTimeString())->toBe('2026-03-29 22:00:00');
});

it('takes the previous period by as many grains as the range has buckets, else by whole months, else by days', function (string $since, string $until, ?string $grain, array $previous) {
    expect(rangeDays(Range::resolve($since, $until, new DateTimeZone('Europe/Berlin'))->previous($grain)))->toBe($previous);
})->with([
    'a month, without grain' => ['2026-09-01', '2026-09-30', null, ['2026-08-01', '2026-08-31']],
    'three months, without grain' => ['2026-07-01', '2026-09-30', null, ['2026-04-01', '2026-06-30']],
    'part of a month, without grain' => ['2026-09-01', '2026-09-15', null, ['2026-08-17', '2026-08-31']],
    'five weekly buckets from a Wednesday' => ['2026-09-02', '2026-09-30', 'week', ['2026-07-29', '2026-08-26']],
    'three monthly buckets from a month\'s last day' => ['2026-01-31', '2026-03-15', 'month', ['2025-10-31', '2025-12-15']],
    'the days of a range across an offset change' => ['2026-03-20', '2026-04-05', 'day', ['2026-03-03', '2026-03-19']],
]);

it('lists the first day of each bucket a range touches, weeks from Monday, at most as many as asked', function (string $grain, int $most, array $buckets) {
    expect(Range::resolve('2026-09-02', '2026-11-03', new DateTimeZone('America/New_York'))->buckets($grain, $most))->toBe($buckets);
})->with([
    'weeks' => ['week', PHP_INT_MAX, ['2026-08-31', '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02']],
    'months' => ['month', PHP_INT_MAX, ['2026-09-01', '2026-10-01', '2026-11-01']],
    'quarters' => ['quarter', PHP_INT_MAX, ['2026-07-01', '2026-10-01']],
    'years' => ['year', PHP_INT_MAX, ['2026-01-01']],
    'the first two days' => ['day', 2, ['2026-09-02', '2026-09-03']],
]);

it('splits a range where the zone\'s offset from the storage zone changes, as the storage zone\'s time and minutes', function (string $zone, string $storage, array $segments) {
    $range = Range::resolve('2026-03-01', '2026-11-30', new DateTimeZone($zone));

    expect($range->segments(new DateTimeZone($storage)))->toBe($segments);
})->with([
    'Berlin, stored in UTC' => ['Europe/Berlin', 'UTC', [['2026-02-28 23:00:00', 60], ['2026-03-29 01:00:00', 120], ['2026-10-25 01:00:00', 60]]],
    'New York, stored in UTC' => ['America/New_York', 'UTC', [['2026-03-01 05:00:00', -300], ['2026-03-08 07:00:00', -240], ['2026-11-01 06:00:00', -300]]],
    'Berlin, stored in New York' => ['Europe/Berlin', 'America/New_York', [['2026-02-28 18:00:00', 360], ['2026-03-08 03:00:00', 300], ['2026-03-28 21:00:00', 360], ['2026-10-24 21:00:00', 300], ['2026-11-01 01:00:00', 360]]],
    'UTC, stored in UTC' => ['UTC', 'UTC', [['2026-03-01 00:00:00', 0]]],
]);
