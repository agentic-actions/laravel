<?php

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Facades\Actions;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\OutcomeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Datasets\GlobalScopedPosts;
use Tests\Fixtures\Datasets\Posts;
use Tests\Fixtures\Datasets\ScopedPost;
use Tests\Fixtures\Datasets\ScopedTeam;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A dataset counts only the rows the tenant may see: the package applies the tenant's scope as find() does, the model's
 * global scopes stay, and the dataset's own scope() only narrows them.
 */

beforeEach(function () {
    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [Posts::class, GlobalScopedPosts::class],
    ]);

    $this->refreshActions();
    [Posts::$scope, Posts::$zone, GlobalScopedPosts::$scope, ScopedPost::$team, ScopedTeam::$unseen] = [null, '', null, null, null];

    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->own = Team::factory()->create(['name' => 'Blue']);
    $this->other = Team::factory()->create(['name' => 'Red']);
    $this->own->users()->attach($this->user);

    // The own team has one draft and one archived post; the other team two published posts and a draft.
    foreach ([[$this->own, 'draft'], [$this->own, 'archived'], [$this->other, 'published'], [$this->other, 'published'], [$this->other, 'draft']] as [$team, $status]) {
        Post::factory()->for($this->user)->forTeam($team)->create(['status' => $status]);
    }
});

/**
 * Ask a dataset for its posts by status, as the test's person in a team, or in none.
 *
 * @param  class-string  $dataset
 */
function askScoped(string $dataset, ?Team $team = null, array $input = []): Outcome
{
    return Actions::attempt($dataset, ['measures' => ['posts'], 'by' => ['status'], ...$input], ActionContext::http(test()->user, $team));
}

/**
 * An outcome's rows as status => posts.
 *
 * @return array<string, int>
 */
function postsByStatus(Outcome $outcome): array
{
    return array_column((array) ($outcome->output()['rows'] ?? []), 'posts', 'status');
}

it('counts only the tenant\'s rows, and scope() narrows them even with an or', function (?Closure $scope, array $rows) {
    $this->useTeamTenancy();
    Posts::$scope = $scope;

    expect(postsByStatus(askScoped(Posts::class, $this->own)))->toBe($rows);
})->with([
    'no conditions of its own' => [null, ['Archived' => 1, 'Draft' => 1]],
    'an or of its own' => [fn (Builder $query) => $query->where('status', 'archived')->orWhere('status', 'published'), ['Archived' => 1]],
]);

it('counts only the tenant\'s rows when the tenant scope itself ends in an or', function () {
    config(['agentic-actions.tenant.model' => Team::class, 'agentic-actions.tenant.membership' => null, 'agentic-actions.tenant.scope' => null]);
    $this->refreshActions();

    app(ActionsManager::class)->membershipUsing(fn (): bool => true);
    // The own team's posts, and every archived post of any team.
    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => $query->where('team_id', $tenant->getKey())->orWhere('status', 'archived'));
    Post::factory()->for($this->user)->forTeam($this->other)->create(['status' => 'archived']);
    Posts::$scope = fn (Builder $query) => $query->where('status', 'draft')->orWhere('status', 'published');

    // Without the grouping, "team_id = ? or status = ? and (status = ? or status = ?)" would count the other team's
    // published posts; with it, only the own team's draft is counted.
    expect(postsByStatus(askScoped(Posts::class, $this->own)))->toBe(['Draft' => 1]);
});

it('keeps the model\'s own global scope, and nests an or scope() adds', function () {
    ScopedPost::$team = $this->own->getKey();
    GlobalScopedPosts::$scope = fn (Builder $query) => $query->where('status', 'draft')->orWhere('status', 'published');

    expect(postsByStatus(askScoped(GlobalScopedPosts::class)))->toBe(['draft' => 1]);
});

it('fails a scope() that removes a scope or adds anything but conditions, naming the dataset', function (Closure $scope) {
    ScopedPost::$team = $this->own->getKey();
    GlobalScopedPosts::$scope = $scope;

    $outcome = askScoped(GlobalScopedPosts::class);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(LogicException::class)
        ->and($outcome->exception()?->getMessage())->toBe(GlobalScopedPosts::class.': scope() may only add conditions, but it removed a scope, or added a join, union, group, having, order, limit, offset or columns.');
})->with([
    'without the tenant\'s global scope' => [fn (Builder $query) => $query->withoutGlobalScopes()],
    'a join' => [fn (Builder $query) => $query->join('users', 'users.id', '=', 'posts.user_id')],
    'a union' => [fn (Builder $query) => $query->union(Post::query()->select('id'))],
    'a group' => [fn (Builder $query) => $query->groupBy('status')],
    'a having' => [fn (Builder $query) => $query->having('status', '!=', '')],
    'an order' => [fn (Builder $query) => $query->orderBy('id')],
    'a limit' => [fn (Builder $query) => $query->limit(1)],
    'an offset' => [fn (Builder $query) => $query->offset(1)],
    'columns' => [fn (Builder $query) => $query->select('id')],
]);

it('keeps the model\'s global scope when scope() removes it inside a nested condition, on any Laravel', function () {
    ScopedPost::$team = $this->own->getKey();
    GlobalScopedPosts::$scope = fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->withoutGlobalScopes()->orWhereNotNull('id'));

    $outcome = askScoped(GlobalScopedPosts::class);

    // Laravel 13 carries a nested builder's removed scopes up to the query, and the package refuses the call. Laravel
    // 12 leaves them on the nested builder, whose scopes never reach the query, so the model's own still applies.
    if (ScopedPost::query()->where(fn (Builder $nested) => $nested->withoutGlobalScopes())->removedScopes() !== []) {
        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception()?->getMessage())->toBe(GlobalScopedPosts::class.': scope() may only add conditions, but it removed a scope, or added a join, union, group, having, order, limit, offset or columns.');
    } else {
        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and(postsByStatus($outcome))->toEqualCanonicalizing(['archived' => 1, 'draft' => 1]);
    }
});

it('reads a related row outside its own model\'s scopes as null', function () {
    ScopedPost::$team = $this->other->getKey();
    ScopedTeam::$unseen = 'Red';

    $rows = askScoped(GlobalScopedPosts::class, input: ['by' => ['team']])->output()['rows'] ?? null;

    expect($rows)->toBe([['team' => null, 'posts' => 3]]);
});

it('refuses a scope() that writes, through the read guard', function () {
    Posts::$scope = fn (Builder $query) => Post::query()->update(['title' => 'Changed']);

    $outcome = askScoped(Posts::class);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and(Post::query()->where('title', 'Changed')->exists())->toBeFalse();
});

it('fails when the tenant scope answers with a query for another model', function () {
    config(['agentic-actions.tenant.model' => Team::class, 'agentic-actions.tenant.membership' => null, 'agentic-actions.tenant.scope' => null]);
    $this->refreshActions();

    app(ActionsManager::class)->membershipUsing(fn (): bool => true);
    app(ActionsManager::class)->scopeUsing(fn (Builder $query, Model $tenant): Builder => User::query());

    $outcome = askScoped(Posts::class, $this->own);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception()?->getMessage())->toBe('The tenant scope returned a query for another model than ['.Post::class.'].');
});
