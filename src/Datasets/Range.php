<?php

namespace AgenticActions\Datasets;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The days a dataset call covers, from local midnight to local midnight in the dataset's zone, the end excluded, and
 * what follows from them: the previous period, the buckets of a grain and where the zone's offset changes.
 *
 * @internal
 */
final class Range
{
    /**
     * The grains a time dimension offers, finest first.
     */
    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    /**
     * Create a range.
     */
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * The range from its first day to its last, both included, each a date or a bound date() resolves.
     *
     * @throws InvalidArgumentException for a bound that is no date
     */
    public static function resolve(string $since, string $until, DateTimeZone $zone): self
    {
        $day = fn (string $bound): CarbonImmutable => CarbonImmutable::createFromFormat('!Y-m-d', self::date($bound, $zone), $zone)
            ?? throw new InvalidArgumentException("[{$bound}] is not a date.");

        return new self($day($since), $day($until)->addDay());
    }

    /**
     * A bound as a date in the zone: "today"; "-{n}{d|w|m|q|y}" as the first day of the unit n - 1 units before today's,
     * so "-8w" is the Monday seven weeks before this week's; any other string as it is.
     */
    public static function date(string $bound, DateTimeZone $zone): string
    {
        $today = CarbonImmutable::today($zone);

        if (preg_match('/^-([1-9][0-9]{0,2})([dwmqy])$/D', $bound, $relative) === 1) {
            $grain = self::GRAINS[(int) strpos('dwmqy', $relative[2])];

            return self::floor($today, $grain)->sub($grain, (int) $relative[1] - 1)->toDateString();
        }

        return $bound === 'today' ? $today->toDateString() : $bound;
    }

    /**
     * The period before, as long as this one. With a grain, the range moved back by as many grains as it has buckets,
     * so bucket i compares with bucket i; without one, by its months when it starts and ends on a month's first day,
     * else by its days.
     */
    public function previous(?string $grain): self
    {
        $grain ??= $this->start->day === 1 && $this->end->day === 1 ? 'month' : 'day';
        $months = ['month' => 1, 'quarter' => 3, 'year' => 12][$grain] ?? 0;
        $utc = new DateTimeZone('UTC');

        $count = $grain === 'day'
            ? (int) (new DateTimeImmutable($this->start->toDateString(), $utc))->diff(new DateTimeImmutable($this->end->toDateString(), $utc))->days
            : count($this->buckets($grain));

        $back = fn (CarbonImmutable $day): CarbonImmutable => $months > 0
            ? $day->subMonthsNoOverflow($count * $months)
            : $day->subDays($count * ($grain === 'week' ? 7 : 1));

        return new self($back($this->start), $back($this->end));
    }

    /**
     * The first day of each bucket of a grain the range touches, in order, at most $most of them. A week starts on
     * Monday.
     *
     * @return list<string>
     */
    public function buckets(string $grain, int $most = PHP_INT_MAX): array
    {
        $buckets = [];

        for ($bucket = self::floor($this->start, $grain); $bucket < $this->end && count($buckets) < $most; $bucket = $bucket->add($grain, 1)) {
            $buckets[] = $bucket->toDateString();
        }

        return $buckets;
    }

    /**
     * Where the zone's offset less the storage zone's changes within the range: the range's start, then each change,
     * as the storage zone's wall-clock time and the minutes a stored time moves to read in the zone.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function segments(DateTimeZone $storage): array
    {
        [$from, $to, $zone] = [$this->start->getTimestamp(), $this->end->getTimestamp(), $this->start->getTimezone()];
        $instants = [$from];

        foreach ([$zone, $storage] as $each) {
            foreach ($each->getTransitions($from, $to) ?: [] as $transition) {
                if ($transition['ts'] > $from && $transition['ts'] < $to) {
                    $instants[] = $transition['ts'];
                }
            }
        }

        sort($instants);
        $segments = [];

        foreach (array_unique($instants) as $instant) {
            $at = new DateTimeImmutable('@'.$instant);
            $minutes = intdiv($zone->getOffset($at) - $storage->getOffset($at), 60);
            $last = end($segments);

            if ($last === false || $last[1] !== $minutes) {
                $segments[] = [$at->setTimezone($storage)->format('Y-m-d H:i:s'), $minutes];
            }
        }

        return $segments;
    }

    /**
     * The first day of the grain's bucket that holds a day: Monday for a week.
     */
    private static function floor(CarbonImmutable $day, string $grain): CarbonImmutable
    {
        return match ($grain) {
            'week' => $day->startOfWeek(CarbonInterface::MONDAY),
            'month' => $day->startOfMonth(),
            'quarter' => $day->startOfQuarter(),
            'year' => $day->startOfYear(),
            default => $day->startOfDay(),
        };
    }
}
