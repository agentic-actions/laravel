<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Tests\Fixtures\Datasets\Posts;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * The widest questions the rules allow, compared with the period before them: eleven centuries, whose previous period
 * reaches back past year 1000, and the longest series of each grain, in a zone whose clocks change twice a year.
 */

beforeEach(function () {
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [Posts::class]]);

    $this->refreshActions();
    [Posts::$scope, Posts::$zone] = [null, 'Europe/Berlin'];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->create(['created_at' => '2026-09-10 10:00:00']);
});

it('answers the widest questions the rules allow', function (array $input) {
    $outcome = Actions::attempt(Posts::class, ['measures' => ['posts', 'authors', 'published_share'], ...$input], ActionContext::http($this->user));

    expect($outcome->kind())->toBe(OutcomeKind::Ok, (string) $outcome->exception()?->getMessage());
})->with([
    'eleven centuries, compared' => [['since' => '1900-01-01', 'until' => '2999-12-31', 'compare' => true]],
    'eleven centuries by team and status, compared' => [['since' => '1900-01-01', 'until' => '2999-12-31', 'compare' => true, 'by' => ['team', 'status'], 'limit' => 500]],
    '500 years, by year, compared' => [['since' => '1900-01-01', 'until' => '2399-12-31', 'grain' => 'year', 'compare' => true]],
    '500 years to 2999, by year, compared' => [['since' => '2500-01-01', 'until' => '2999-12-31', 'grain' => 'year', 'compare' => true]],
    '125 years, by quarter, compared' => [['since' => '1900-01-01', 'until' => '2024-12-31', 'grain' => 'quarter', 'compare' => true]],
    '500 weeks, by week, compared' => [['since' => '-500w', 'grain' => 'week', 'compare' => true]],
])->group('database');
