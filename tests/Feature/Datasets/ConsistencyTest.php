<?php

use AgenticActions\ActionContext;
use AgenticActions\Datasets\Range;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Random valid calls over random posts, drawn from a fixed seed so a failure reproduces. Each call answers, and the
 * answers agree with each other: groups add up to the total, a series adds up to its range's total, and the previous
 * period of a series or of a group holds what asking for that period directly gives.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [Posts::class]]);

    $this->refreshActions();
    [Posts::$scope, Posts::$zone] = [null, ''];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    mt_srand(20261001);

    // 600 posts from January 2024 to September 2026, in no team or one of three, with one of the three statuses.
    $teams = [null, Team::factory()->create(['name' => 'Blue']), Team::factory()->create(['name' => 'Red']), Team::factory()->create(['name' => 'Green'])];
    $statuses = ['draft', 'published', 'archived'];
    $rows = [];

    for ($i = 0; $i < 600; $i++) {
        $rows[] = [
            'user_id' => $this->user->getKey(),
            'team_id' => $teams[mt_rand(0, 3)]?->getKey(),
            'title' => 't',
            'body' => 'b',
            'status' => $statuses[mt_rand(0, 2)],
            'created_at' => date('Y-m-d H:i:s', mt_rand(1704067200, 1790000000)),
        ];
    }

    Post::query()->insert($rows);
});

/**
 * The Posts dataset's rows for a call, as the test's person; the test fails on any other outcome than an answer.
 *
 * @param  array<string, mixed>  $input
 * @return list<array<string, mixed>>
 */
function consistentRows(array $input): array
{
    $outcome = Actions::attempt(Posts::class, $input, ActionContext::http(test()->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, json_encode($input).' '.$outcome->exception()?->getMessage().json_encode($outcome->errors()));

    return $outcome->output()['rows'] ?? [];
}

it('answers random calls, and the answers agree with each other', function (string $zone) {
    Posts::$zone = $zone;
    $days = new DateTimeZone($zone === '' ? 'UTC' : $zone);

    for ($round = 0; $round < 6; $round++) {
        $since = date('Y-m-d', mt_rand(1704067200, 1780000000));
        $until = date('Y-m-d', min(strtotime($since) + mt_rand(0, 400) * 86400, 1790000000));
        $filters = mt_rand(0, 1) === 1 ? [['dimension' => 'status', 'values' => ['published', 'draft'], 'exclude' => mt_rand(0, 1) === 1]] : [];
        $call = ['measures' => ['posts', 'authors', 'published_share'], 'since' => $since, 'until' => $until, 'filters' => $filters];
        $asked = json_encode($call);

        $total = consistentRows($call)[0]['posts'];

        // Groups add up to the total.
        expect(array_sum(array_column(consistentRows([...$call, 'by' => ['team'], 'limit' => 500]), 'posts')))->toBe($total, "by team: {$asked}")
            ->and(array_sum(array_column(consistentRows([...$call, 'by' => ['team', 'status'], 'limit' => 500]), 'posts')))->toBe($total, "by team and status: {$asked}");

        foreach (Range::GRAINS as $grain) {
            $range = Range::resolve($since, $until, $days);

            if (count($range->buckets($grain, 501)) > 500) {
                continue;
            }

            // A series adds up to its range's total, and each bucket's previous value is that bucket's own count, as
            // many buckets earlier as the range has.
            $previous = $range->previous($grain);
            $earlier = consistentRows([...$call, 'grain' => $grain, 'since' => $previous->start->toDateString(), 'until' => $previous->end->subDay()->toDateString()]);

            expect(array_sum(array_column(consistentRows([...$call, 'grain' => $grain]), 'posts')))->toBe($total, "{$grain}: {$asked}")
                ->and(array_column(consistentRows([...$call, 'grain' => $grain, 'compare' => true]), 'posts_previous'))->toBe(array_column($earlier, 'posts'), "{$grain} compared: {$asked}");
        }

        // Each group's previous value is what that group had when the previous period is asked for directly.
        $previous = Range::resolve($since, $until, $days)->previous(null);
        $then = array_column(consistentRows([...$call, 'since' => $previous->start->toDateString(), 'until' => $previous->end->subDay()->toDateString(), 'by' => ['team'], 'limit' => 500]), 'posts', 'team');

        foreach (consistentRows([...$call, 'by' => ['team'], 'compare' => true, 'limit' => 500]) as $row) {
            expect($row['posts_previous'])->toBe($then[$row['team'] ?? ''] ?? 0, 'compared by team: '.json_encode([$call, $row]));
        }
    }
})->with([
    'UTC' => [''],
    'Europe/Berlin' => ['Europe/Berlin'],
    'America/New_York' => ['America/New_York'],
    'Australia/Lord_Howe' => ['Australia/Lord_Howe'],
    'Asia/Kathmandu' => ['Asia/Kathmandu'],
])->group('database');
