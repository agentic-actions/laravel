<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Tests\Fixtures\Datasets\PostTitles;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The previous period is matched to the current groups by the database, by the collation it grouped them by. On MySQL
 * and MariaDB, which compare text case- and accent-insensitively, a match by the bytes of each value in PHP read a
 * group's previous value as 0 when last month spelled it another way: a wrong number, silently.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [PostTitles::class]]);
    $this->refreshActions();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

it('gives each group the previous value the database itself counts for it', function (string $now, string $then) {
    // This month two posts of one group and one of another; last month three and four, spelled a way a collation may
    // call equal.
    $posts = [
        [$now, '2026-09-10'], [$now, '2026-09-11'], ['Hiring', '2026-09-12'],
        [$then, '2026-08-10'], [$then, '2026-08-11'], [$then, '2026-08-12'],
        ['HIRING', '2026-08-13'], ['HIRING', '2026-08-14'], ['HIRING', '2026-08-15'], ['HIRING', '2026-08-16'],
    ];

    foreach ($posts as [$title, $day]) {
        Post::factory()->for($this->user)->create(['title' => $title, 'created_at' => "{$day} 10:00:00"]);
    }

    $outcome = Actions::attempt(PostTitles::class, ['measures' => ['posts'], 'by' => ['title'], 'since' => '-1m', 'compare' => true], ActionContext::http($this->user));
    $rows = $outcome->output()['rows'] ?? [];

    // What last month held for a group, by the database's own comparison: 3 and 4 where it calls the spellings equal.
    $counted = fn (array $row): int => Post::query()->where('title', $row['title'])->whereBetween('created_at', ['2026-08-01 00:00:00', '2026-08-31 23:59:59'])->count();

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage())
        ->and(array_column($rows, 'posts'))->toBe([2, 1])
        ->and(array_column($rows, 'posts_previous'))->toBe(array_map($counted, $rows));
})->with([
    'case' => ['Launch', 'LAUNCH'],
    'accents' => ['Jose', 'José'],
    'a trailing space' => ['Launch', 'Launch '],
])->group('database');
