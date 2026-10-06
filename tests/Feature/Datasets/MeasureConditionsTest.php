<?php

use AgenticActions\ActionContext;
use AgenticActions\Datasets\Measure;
use AgenticActions\Facades\Actions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Fixtures\Datasets\PostConditions;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A measure's where() compares with an operator, or with null: only the rows it matches count, or add to a sum.
 */

beforeEach(function () {
    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PostConditions::class],
    ]);

    $this->refreshActions();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

it('counts and sums only the rows each condition matches', function () {
    $ids = [];

    foreach ([['A short one', 'draft'], [null, 'published'], ['Another', 'published'], [null, 'archived']] as [$excerpt, $status]) {
        $ids[] = Post::factory()->for($this->user)->create(['excerpt' => $excerpt, 'status' => $status, 'created_at' => '2026-09-30 09:00:00'])->id;
    }

    $rows = Actions::attempt(PostConditions::class, ['measures' => ['excerpted', 'bare', 'not_drafts', 'later_ids']], ActionContext::http($this->user))->output()['rows'] ?? [];

    expect($rows)->toBe([['excerpted' => 2, 'bare' => 2, 'not_drafts' => 3, 'later_ids' => array_sum(array_filter($ids, fn (int $id): bool => $id > 2))]]);
})->group('database');

it('reads two arguments as equals, as Laravel\'s where() does', function () {
    expect(Measure::count('published', 'Published')->where('status', 'published')->condition())->toBe(['status', '=', 'published'])
        ->and(Measure::count('bare', 'Bare')->where('excerpt', null)->condition())->toBe(['excerpt', '=', null]);
});

it('compares a Stringable value, such as a Carbon date or Str::of(), as its string', function () {
    expect(Measure::count('today', 'Today')->where('created_at', today())->condition())->toBe(['created_at', '=', '2026-09-30 00:00:00'])
        ->and(Measure::count('published', 'Published')->where('status', Str::of('published'))->condition())->toBe(['status', '=', 'published']);
});

it('refuses an operator it does not know, and null with an operator other than = or !=', function (string $operator, mixed $value) {
    expect(fn () => Measure::count('posts', 'Posts')->where('id', $operator, $value))->toThrow(InvalidArgumentException::class);
})->with([
    'like' => ['like', '%a%'],
    'an injection' => ['= 1 or 1 =', 1],
    'null with >' => ['>', null],
]);
