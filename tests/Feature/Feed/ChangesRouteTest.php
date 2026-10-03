<?php

use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Feed\ChangesController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Feed\Fixtures\FeedDraftPost;
use Tests\Feature\Feed\Fixtures\FeedNamedChanges;
use Tests\Feature\Feed\Fixtures\FeedTeamPost;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The feed route: one per Actions::routes() group, behind the group's own middleware, answering only a signed-in
 * session that may enter the group's tenant, with the same 404 body for everything else.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['agentic-actions.discovery.classes' => [FeedDraftPost::class, FeedTeamPost::class]]);
    $this->useTeamTenancy();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);
});

/**
 * Mount the groups an app writes: account and tenant groups in web, a token group, and a group with no auth.
 */
function feedMountGroups(): void
{
    test()->mountRoutes(function () {
        Route::middleware(['web', 'auth'])->group(fn () => Actions::routes(tenant: false));
        Route::middleware(['web', 'auth'])->prefix('teams/{team}')->name('teams.')->group(fn () => Actions::routes(tenant: true));
        Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => Actions::routes(tenant: false));
        Route::middleware('web')->prefix('open')->name('open.')->group(fn () => Actions::routes(tenant: false));
    });
}

/**
 * The feed routes, as [methods, uri, name].
 *
 * @return list<array{0: list<string>, 1: string, 2: string|null}>
 */
function feedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->getControllerClass() === ChangesController::class)
        ->map(fn (RoutingRoute $route): array => [$route->methods(), $route->uri(), $route->getName()])
        ->values()
        ->all();
}

describe('the route', function () {
    it('adds POST …/actions/_changes to each Actions::routes() group, named after the group', function () {
        feedMountGroups();

        expect(feedRoutes())->toBe([
            [['POST'], 'actions/_changes', 'actions._changes'],
            [['POST'], 'teams/{team}/actions/_changes', 'teams.actions._changes'],
            [['POST'], 'api/actions/_changes', 'api.actions._changes'],
            [['POST'], 'open/actions/_changes', 'open.actions._changes'],
        ])->and(route('teams.actions._changes', ['team' => 'acme'], false))->toBe('/teams/acme/actions/_changes');
    });

    it('registers nothing with feed.enabled off', function () {
        config(['agentic-actions.feed.enabled' => false]);

        feedMountGroups();

        expect(feedRoutes())->toBe([])
            ->and(Route::getRoutes()->getByName('teams.actions.feed-team-post'))->not->toBeNull();
    });

    it('refuses an action that takes the name _changes', function () {
        config(['agentic-actions.discovery.classes' => [FeedNamedChanges::class]]);
        $this->refreshActions();

        expect(fn () => Actions::routes())->toThrow(MisconfiguredExposure::class, FeedNamedChanges::class.': the name [_changes] is the change feed\'s route; set another $name.');
    });

    it('passes through the group\'s middleware, the web group\'s forgery check included', function () {
        feedMountGroups();

        $forgery = collect(app('router')->getMiddlewareGroups()['web'])
            ->first(fn (mixed $middleware): bool => is_string($middleware) && (str_contains($middleware, 'Forgery') || str_contains($middleware, 'Csrf')));
        $gathered = Route::gatherRouteMiddleware(Route::getRoutes()->getByName('teams.actions._changes'));

        expect($forgery)->toBeString()
            ->and($gathered)->toContain($forgery, 'Illuminate\Auth\Middleware\Authenticate');
    });
});

describe('a poll', function () {
    beforeEach(function () {
        $this->freezeTime();

        feedMountGroups();
    });

    it('answers a first poll with nothing, and another member\'s write in the next', function () {
        $teammate = User::factory()->create();
        $this->team->users()->attach($teammate);

        $first = $this->actingAs($this->user)->postJson('/teams/acme/actions/_changes', ['since' => null])
            ->assertOk()
            ->assertExactJson(['now' => (int) now()->getTimestampMs() - 2000, 'touches' => []]);

        $this->travel(3)->seconds();
        $this->actingAs($teammate)->postJson('/teams/acme/actions/feed-team-post', ['title' => 'Hello'])->assertOk();
        $this->travel(12)->seconds();

        $this->actingAs($this->user)->postJson('/teams/acme/actions/_changes', ['since' => $first->json('now')])
            ->assertOk()
            ->assertExactJson(['now' => (int) now()->getTimestampMs() - 2000, 'touches' => ['posts']]);
    });

    it('keeps an account group to the actor\'s own bucket', function () {
        $teammate = User::factory()->create();
        $this->team->users()->attach($teammate);
        $since = (int) now()->getTimestampMs() - 1000;

        $this->actingAs($teammate)->postJson('/teams/acme/actions/feed-team-post', ['title' => 'Hello'])->assertOk();

        $this->actingAs($this->user)->postJson('/actions/_changes', ['since' => $since])->assertOk()->assertJsonPath('touches', []);

        $this->actingAs($this->user)->postJson('/actions/feed-draft-post', ['title' => 'Mine'])->assertOk();

        $this->actingAs($this->user)->postJson('/actions/_changes', ['since' => $since])->assertOk()->assertJsonPath('touches', ['posts']);
    });

    it('reads a since that is not an integer as a first poll', function () {
        $this->actingAs($this->user)->postJson('/actions/feed-draft-post', ['title' => 'Mine'])->assertOk();

        foreach (['1', 1.5, [], 'abc'] as $since) {
            $this->actingAs($this->user)->postJson('/actions/_changes', ['since' => $since])->assertOk()->assertJsonPath('touches', []);
        }
    });

    it('answers a session on a token group, as a Sanctum SPA cookie is', function () {
        $this->actingAs($this->user, 'web')->postJson('/api/actions/_changes', ['since' => null])
            ->assertOk()
            ->assertJsonPath('touches', []);
    });
});
