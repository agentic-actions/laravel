<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Each time stored in UTC falls in the day, week and month PHP gives it in the dataset's zone, around each 2026 change
 * and across the year: clocks that change at midnight, offsets of a half or three quarters of an hour, a change of half
 * an hour and one of two hours, a summer time that is the zone's standard one (Dublin), a change for Ramadan
 * (Casablanca), and the two sides of the date line.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [Posts::class]]);

    $this->refreshActions();
    Posts::$scope = null;

    $this->travelTo(CarbonImmutable::parse('2026-12-31 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

it('puts every time in the bucket PHP gives it in the dataset\'s zone', function (string $zone) {
    Posts::$zone = $zone;
    $times = [];

    // Every 37 minutes for 15 hours on each side of each 2026 change, and one every three days and 72 minutes.
    foreach ((new DateTimeZone($zone))->getTransitions(strtotime('2026-01-01'), strtotime('2027-01-01')) ?: [] as $transition) {
        for ($time = $transition['ts'] - 15 * 3600; $time < $transition['ts'] + 15 * 3600; $time += 37 * 60) {
            $times[] = $time;
        }
    }

    for ($time = strtotime('2026-01-01'); $time < strtotime('2027-01-01'); $time += 86400 * 3 + 4321) {
        $times[] = $time;
    }

    $times = array_values(array_filter($times, fn (int $time): bool => $time >= strtotime('2026-01-02') && $time < strtotime('2026-12-30')));
    $rows = array_map(fn (int $time): array => ['user_id' => $this->user->getKey(), 'title' => 't', 'body' => 'b', 'status' => 'draft', 'created_at' => gmdate('Y-m-d H:i:s', $time)], $times);

    foreach (array_chunk($rows, 500) as $chunk) {
        Post::query()->insert($chunk);
    }

    foreach (['day' => ['2026-01-02', '2026-12-29'], 'week' => ['2026-01-05', '2026-12-27'], 'month' => ['2026-02-01', '2026-11-30']] as $grain => [$since, $until]) {
        $expected = [];

        foreach ($times as $time) {
            $local = CarbonImmutable::createFromTimestamp($time, $zone);

            if ($local->toDateString() < $since || $local->toDateString() > $until) {
                continue;
            }

            $bucket = match ($grain) {
                'day' => $local->toDateString(),
                'week' => $local->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'month' => $local->startOfMonth()->toDateString(),
            };
            $expected[$bucket] = ($expected[$bucket] ?? 0) + 1;
        }

        ksort($expected);
        $outcome = Actions::attempt(Posts::class, ['measures' => ['posts'], 'grain' => $grain, 'since' => $since, 'until' => $until], ActionContext::http($this->user));

        expect(array_sum($expected))->toBeGreaterThan(50)
            ->and($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
            ->and(array_filter(array_column($outcome->output()['rows'] ?? [], 'posts', 'created')))->toBe($expected, "{$zone} by {$grain}");
    }
})->with([
    'America/Santiago', 'America/Havana', 'Asia/Beirut', 'Asia/Kathmandu', 'America/St_Johns', 'Pacific/Chatham',
    'Australia/Lord_Howe', 'Africa/Casablanca', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Europe/Dublin', 'Antarctica/Troll',
])->group('database');
