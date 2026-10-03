<?php

use AgenticActions\ActionContext;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Schema\AdvertisedSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Datasets\GlobalScopedPosts;
use Tests\Fixtures\Datasets\Invalid\BadDeclarations;
use Tests\Fixtures\Datasets\PostNumbers;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A dataset's call is generated from its declarations: a model can name only the measures, dimensions, grains and sort
 * keys the dataset declares, and a call outside what they allow is refused as invalid, naming the key, before any
 * query runs.
 */

beforeEach(function () {
    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [Posts::class, PostNumbers::class, GlobalScopedPosts::class, BadDeclarations::class],
    ]);

    $this->refreshActions();
    [Posts::$scope, Posts::$zone, BadDeclarations::$dimensions] = [null, '', null];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

/**
 * Ask the Posts dataset as the test's person, and the statements it sent to the database meanwhile.
 *
 * @param  array<string, mixed>  $input
 * @return array{0: Outcome, 1: list<string>}
 */
function askPostsCounting(array $input): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    return [Actions::attempt(Posts::class, $input, ActionContext::http(test()->user)), $statements];
}

it('offers agents a call made only of the declared measures, dimensions, grains and sort keys', function () {
    $node = app(AdvertisedSchema::class)->node(new Posts, ActionContext::agent($this->user));
    $properties = $node['properties'];

    expect(array_keys($properties))->toBe(['measures', 'by', 'grain', 'since', 'until', 'filters', 'compare', 'sort', 'ascending', 'limit'])
        ->and($node['required'])->toBe(['measures'])
        ->and($properties['measures']['items']['enum'])->toBe(['posts', 'authors', 'published', 'published_share', 'posts_per_published'])
        ->and($properties['by']['items']['enum'])->toBe(['team', 'status'])
        ->and($properties['grain']['enum'])->toBe(['day', 'week', 'month', 'quarter', 'year'])
        ->and($properties['filters']['items']['properties']['dimension']['enum'])->toBe(['team', 'status'])
        ->and($properties['sort']['enum'])->toBe(['created', 'team', 'status', 'posts', 'authors', 'published', 'published_share', 'posts_per_published'])
        ->and($properties['measures']['description'])->toContain('posts: Posts, The number of posts; authors: Authors;')
        ->and($properties['by']['description'])->toContain('team: Team, The team the post belongs to; status: Status.');
});

it('leaves out the keys a dataset has nothing to offer for', function (string $dataset, array $keys) {
    expect(array_keys(app(AdvertisedSchema::class)->node(new $dataset, ActionContext::agent($this->user))['properties']))->toBe($keys);
})->with([
    'no dimension but the time' => [PostNumbers::class, ['measures', 'grain', 'since', 'until', 'compare', 'sort', 'ascending', 'limit']],
    'no time dimension' => [GlobalScopedPosts::class, ['measures', 'by', 'filters', 'sort', 'ascending', 'limit']],
]);

it('refuses a call outside what the declarations allow, naming the key, before any query', function (array $input, array $rules) {
    Post::factory()->for($this->user)->create();

    [$outcome, $statements] = askPostsCounting(['measures' => ['posts'], ...$input]);

    expect($outcome->kind())->toBe(OutcomeKind::Invalid)
        ->and($outcome->failedRules())->toBe($rules)
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'posts')))->toBe([]);
})->with([
    'a measure it does not declare' => [['measures' => ['words']], ['measures.0' => ['in']]],
    'a column as a dimension' => [['by' => ['posts.user_id']], ['by.0' => ['in']]],
    'an expression as the sort' => [['sort' => 'count(*)'], ['sort' => ['in', 'chosen_measure_or_dimension']]],
    'a value outside the enum' => [['filters' => [['dimension' => 'status', 'values' => ['deleted']]]], ['filters.0' => ['dimension_values']]],
    'a value over 255 characters' => [['filters' => [['dimension' => 'team', 'values' => [str_repeat('a', 256)]]]], ['filters.0.values.0' => ['max']]],
    'one dimension filtered twice' => [['filters' => [['dimension' => 'team', 'values' => ['Blue']], ['dimension' => 'team', 'values' => ['Red']]]], ['filters.0.dimension' => ['distinct'], 'filters.1.dimension' => ['distinct']]],
    'a date before 1900' => [['since' => '1899-12-31'], ['since' => ['after_or_equal']]],
    'a relative first day before 1900' => [['since' => '-999y'], ['since' => ['after_or_equal']]],
    'a day that does not exist' => [['since' => '2026-02-30'], ['since' => ['date_format']]],
    'a first day after the last' => [['since' => '2026-09-10', 'until' => '2026-09-01'], ['since' => ['before_or_equal']]],
    'grain with by' => [['grain' => 'day', 'by' => ['team']], ['grain' => ['prohibits']]],
    'a sort the call did not choose' => [['sort' => 'authors'], ['sort' => ['chosen_measure_or_dimension']]],
    'the time as sort without grain' => [['sort' => 'created'], ['sort' => ['chosen_measure_or_dimension']]],
]);

it('refuses a series with more buckets than a table holds, and the refusal names the fix', function () {
    config(['agentic-actions.views.max_rows' => 10]);

    [$outcome] = askPostsCounting(['measures' => ['posts'], 'grain' => 'day', 'since' => '2026-09-20']);

    expect($outcome->failedRules())->toBe(['grain' => ['coarser_grain_or_shorter_range']])
        ->and($outcome->errors())->toBe(['grain' => [trans('agentic-actions::views.buckets')]])
        ->and($outcome->forModel())->toBe('Not done. Rejected: grain (coarser_grain_or_shorter_range).')
        ->and(askPostsCounting(['measures' => ['posts'], 'grain' => 'day', 'since' => '2026-09-21'])[0]->kind())->toBe(OutcomeKind::Ok);
});

it('fails a dataset whose relation is not one it reads, without calling the method the relation names', function () {
    BadDeclarations::$dimensions = fn (): array => [Dimension::text('saved', 'Saved', 'save.x')];

    $outcome = Actions::attempt(BadDeclarations::class, ['measures' => ['posts']], ActionContext::http($this->user));

    // Calling save() on a new post would insert a row, and fail on its missing author before the check.
    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(InvalidArgumentException::class)
        ->and($outcome->exception()?->getMessage())->toStartWith('[save] is not a relation a dataset reads')
        ->and(Post::query()->count())->toBe(0);
});

it('never asks the person for what a model\'s call got wrong, whatever $askForMissing says', function () {
    // A sort outside the enum is a choice a form could hold, so an asking action would show one.
    $asked = app(Runner::class)->preview(ClassExposure::of(Posts::class), ['measures' => ['posts'], 'sort' => 'words'], ActionContext::agent($this->user), ['default']);

    expect($asked)->toBeNull();
});
