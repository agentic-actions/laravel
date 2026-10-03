<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Datasets\PostNumbers;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The SQL a dataset writes, on each driver the database group runs: SQLite in the suite, MySQL 8 and Postgres 16 in
 * their cells. Times fall in their bucket in the dataset's zone across offset changes, each grain starts on its first
 * day, drivers' numbers read as numbers, ratios and nulls sort the same, the previous period matches each group, and a
 * statement timeout the connection sets becomes the fixed refusal.
 */

beforeEach(function () {
    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [Posts::class, PostNumbers::class],
    ]);

    $this->refreshActions();
    [Posts::$scope, Posts::$zone] = [null, ''];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

/**
 * A dataset's rows for a call, as the test's person.
 *
 * @param  class-string  $dataset
 * @param  array<string, mixed>  $input
 * @return list<array<string, mixed>>
 */
function datasetRows(string $dataset, array $input): array
{
    $outcome = Actions::attempt($dataset, $input, ActionContext::http(test()->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage());

    return $outcome->output()['rows'] ?? [];
}

/**
 * Posts of the test's person, created at these times (stored in UTC), in a team (null for none) and a status.
 *
 * @param  list<array{0: string, 1?: Team|null, 2?: string}>  $posts
 * @return list<Post>
 */
function postsAt(array $posts): array
{
    return array_map(fn (array $post): Post => Post::factory()->for(test()->user)->create([
        'created_at' => $post[0],
        'team_id' => ($post[1] ?? null)?->getKey(),
        'status' => $post[2] ?? 'draft',
    ]), $posts);
}

it('puts each time in its day in the dataset\'s zone, across 2026\'s offset changes', function (string $zone, string $since, string $until, array $times, array $days, int $buckets) {
    Posts::$zone = $zone;
    postsAt(array_map(fn (string $time): array => [$time], $times));

    $rows = datasetRows(Posts::class, ['measures' => ['posts'], 'grain' => 'day', 'since' => $since, 'until' => $until]);

    expect(array_filter(array_column($rows, 'posts', 'created')))->toBe($days)
        ->and($rows)->toHaveCount($buckets);
})->with([
    // Berlin moves from UTC+1 to UTC+2 at 01:00 UTC on 29 March, and back at 01:00 UTC on 25 October.
    'Europe/Berlin' => ['Europe/Berlin', '2026-03-28', '2026-10-26', [
        '2026-03-28 22:30:00', '2026-03-28 23:30:00', '2026-03-29 21:30:00', '2026-03-29 22:30:00',
        '2026-10-24 21:30:00', '2026-10-24 22:30:00', '2026-10-25 22:30:00', '2026-10-25 23:30:00',
    ], ['2026-03-28' => 1, '2026-03-29' => 2, '2026-03-30' => 1, '2026-10-24' => 1, '2026-10-25' => 2, '2026-10-26' => 1], 213],
    // New York moves from UTC-5 to UTC-4 at 07:00 UTC on 8 March, and back at 06:00 UTC on 1 November.
    'America/New_York' => ['America/New_York', '2026-03-07', '2026-11-02', [
        '2026-03-08 04:30:00', '2026-03-08 05:30:00', '2026-03-09 03:30:00', '2026-03-09 04:30:00',
        '2026-10-31 03:30:00', '2026-10-31 04:30:00', '2026-11-01 04:30:00', '2026-11-02 04:30:00', '2026-11-02 05:30:00',
    ], ['2026-03-07' => 1, '2026-03-08' => 2, '2026-03-09' => 1, '2026-10-30' => 1, '2026-10-31' => 1, '2026-11-01' => 2, '2026-11-02' => 1], 241],
])->group('database');

it('starts each grain on its first day in the dataset\'s zone, a week on Monday', function (string $grain, array $buckets) {
    Posts::$zone = 'Europe/Berlin';

    // In Berlin: Sunday 28 June 23:30, Monday 29 June 00:30, Wednesday 1 July 00:30, and Friday 1 January 2027 00:30.
    postsAt([['2026-06-28 21:30:00'], ['2026-06-28 22:30:00'], ['2026-06-30 22:30:00'], ['2026-12-31 23:30:00']]);

    $rows = datasetRows(Posts::class, ['measures' => ['posts'], 'grain' => $grain, 'since' => '2026-06-01', 'until' => '2027-01-31']);

    expect(array_filter(array_column($rows, 'posts', 'created')))->toBe($buckets);
})->with([
    'week' => ['week', ['2026-06-22' => 1, '2026-06-29' => 2, '2026-12-28' => 1]],
    'month' => ['month', ['2026-06-01' => 2, '2026-07-01' => 1, '2027-01-01' => 1]],
    'quarter' => ['quarter', ['2026-04-01' => 2, '2026-07-01' => 1, '2027-01-01' => 1]],
    'year' => ['year', ['2026-01-01' => 3, '2027-01-01' => 1]],
])->group('database');

it('reads sums, averages, minima and maxima as numbers, whatever the driver returns', function () {
    [$first, $last] = postsAt([['2026-09-10 09:00:00', null, 'published'], ['2026-09-11 09:00:00']]);

    expect(datasetRows(PostNumbers::class, ['measures' => ['id_sum', 'id_average', 'first_id', 'last_id', 'published_id_sum']]))->toBe([[
        'id_sum' => $first->id + $last->id,
        'id_average' => ($first->id + $last->id) / 2,
        'first_id' => $first->id,
        'last_id' => $last->id,
        'published_id_sum' => $first->id,
    ]]);
})->group('database');

it('computes a ratio, none over nothing, and sorts by it or by a dimension with no value last', function (string $sort, bool $ascending, array $teams) {
    [$blue, $red, $green] = [Team::factory()->create(['name' => 'Blue']), Team::factory()->create(['name' => 'Red']), Team::factory()->create(['name' => 'Green'])];

    // Posts per published one: Blue 2 of 1, Red 1 of 1, Green 1 of none, no team 4 of 1.
    postsAt([
        ['2026-09-10', $blue, 'published'], ['2026-09-10', $blue], ['2026-09-11', $red, 'published'], ['2026-09-11', $green],
        ['2026-09-12', null, 'published'], ['2026-09-12'], ['2026-09-12'], ['2026-09-12'],
    ]);

    $rows = datasetRows(Posts::class, ['measures' => ['posts_per_published'], 'by' => ['team'], 'sort' => $sort, 'ascending' => $ascending]);

    expect(array_column($rows, 'posts_per_published', 'team'))->toBe($teams);
})->with([
    'the highest first' => ['posts_per_published', false, ['' => 4.0, 'Blue' => 2.0, 'Red' => 1.0, 'Green' => null]],
    'the lowest first' => ['posts_per_published', true, ['Red' => 1.0, 'Blue' => 2.0, '' => 4.0, 'Green' => null]],
    'teams from A, no team last' => ['team', true, ['Blue' => 2.0, 'Green' => null, 'Red' => 1.0, '' => 4.0]],
    'teams from Z, no team last' => ['team', false, ['Red' => 1.0, 'Green' => null, 'Blue' => 2.0, '' => 4.0]],
])->group('database');

it('adds each current group\'s value in the previous period, and its change', function () {
    [$blue, $red, $green] = [Team::factory()->create(['name' => 'Blue']), Team::factory()->create(['name' => 'Red']), Team::factory()->create(['name' => 'Green'])];

    // This month: Blue 2, Red 1, no team 1. Last month: Blue 1, no team 2, and Green 3, which this month lacks.
    postsAt([
        ['2026-09-10', $blue], ['2026-09-11', $blue], ['2026-09-12', $red], ['2026-09-13'],
        ['2026-08-10', $blue], ['2026-08-11'], ['2026-08-12'], ['2026-08-13', $green], ['2026-08-14', $green], ['2026-08-15', $green],
    ]);

    expect(datasetRows(Posts::class, ['measures' => ['posts'], 'by' => ['team'], 'since' => '-1m', 'compare' => true]))->toBe([
        ['team' => 'Blue', 'posts' => 2, 'posts_previous' => 1, 'posts_change' => 1],
        ['team' => 'Red', 'posts' => 1, 'posts_previous' => 0, 'posts_change' => null],
        ['team' => null, 'posts' => 1, 'posts_previous' => 2, 'posts_change' => -0.5],
    ]);
})->group('database');

it('refuses a question the connection\'s statement time limit stopped, and reports nothing', function () {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite sets no statement time limit.');
    }

    Exceptions::fake();
    postsAt([['2026-09-10 09:00:00']]);

    // A condition that takes a fifth of a second, under a limit of one millisecond.
    Posts::$scope = fn (Builder $query) => $query->whereRaw($driver === 'pgsql' ? '(select 1 from pg_sleep(0.2)) = 1' : 'sleep(0.2) = 0');
    // MariaDB names its limit max_statement_time, in seconds; MySQL max_execution_time, in milliseconds.
    [$set, $unset] = match ($driver) {
        'pgsql' => ['set statement_timeout = 1', null],
        'mariadb' => ['set session max_statement_time = 0.001', 'set session max_statement_time = 0'],
        default => ['set session max_execution_time = 1', 'set session max_execution_time = 0'],
    };
    DB::statement($set);

    try {
        $outcome = Actions::attempt(Posts::class, ['measures' => ['posts']], ActionContext::http($this->user));
    } finally {
        // Postgres takes the setting back with the test's transaction; MySQL and MariaDB keep it for the session.
        if ($unset !== null) {
            DB::statement($unset);
        }
    }

    expect($outcome->kind())->toBe(OutcomeKind::Refused)
        ->and($outcome->status())->toBe(409)
        ->and($outcome->forModel())->toBe(trans('agentic-actions::views.too_slow'));

    Exceptions::assertNothingReported();
})->group('database');
