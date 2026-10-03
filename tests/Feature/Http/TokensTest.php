<?php

namespace Tests\Feature\Http;

use AgenticActions\Facades\Actions;
use ArrayObject;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Token grants on the api mount: every credential is read by the guard that authenticated it, and fails closed.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->user = User::factory()->create();
});

/**
 * Mount the web and api groups the README describes.
 */
function mountWebAndApi(): void
{
    test()->mountRoutes(function (): void {
        Route::middleware(['web', 'auth'])->group(fn () => Actions::routes());
        Route::middleware(['api', 'auth:sanctum'])->prefix('api')->name('api.')->group(fn () => Actions::routes());
    });
}

/**
 * Notice-level log lines written from now on.
 *
 * @return ArrayObject<int, string>
 */
function httpNotices(): ArrayObject
{
    $notices = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($notices): void {
        if ($event->level === 'notice') {
            $notices->append($event->message);
        }
    });

    return $notices;
}

it('lets a default Sanctum token reach every effect, with 422 for invalid input, and a read token only Reads', function () {
    mountWebAndApi();

    $all = $this->user->createToken('all')->plainTextToken;
    $read = $this->user->createToken('read', ['actions:read'])->plainTextToken;

    $this->withToken($all)->postJson('/api/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertOk();

    $this->withToken($all)
        ->postJson('/api/actions/create-note', ['body' => 'x'])
        ->assertUnprocessable()
        ->assertJsonStructure(['message', 'errors' => ['title']]);

    $this->app['auth']->forgetGuards();

    $this->withToken($read)
        ->postJson('/api/actions/create-note', ['title' => 'Again', 'body' => 'x'])
        ->assertNotFound()
        ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

    $this->withToken($read)->postJson('/api/actions/list-notes')->assertOk()->assertJsonPath('posts.0.title', 'Hi');

    expect(Post::query()->pluck('title')->all())->toBe(['Hi']);
});

it('reads Sanctum::actingAs() exactly as the real tokens', function () {
    mountWebAndApi();

    Sanctum::actingAs($this->user, ['*']);

    $this->postJson('/api/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertOk();

    Sanctum::actingAs($this->user, ['actions:read']);

    $this->postJson('/api/actions/create-note', ['title' => 'Again', 'body' => 'x'])->assertNotFound();
    $this->postJson('/api/actions/list-notes')->assertOk();

    expect(Post::query()->count())->toBe(1);
});

it('binds a token to one team by primary key, while the route names the team by slug', function () {
    $this->useTeamTenancy();

    $this->mountRoutes(fn () => Route::middleware(['api', 'auth:sanctum'])->prefix('api/teams/{team}')->name('api.teams.')->group(
        fn () => Actions::routes(tenant: true),
    ));

    $bound = Team::factory()->create(['slug' => 'bound']);
    $other = Team::factory()->create(['slug' => 'other']);
    $bound->users()->attach($this->user);
    $other->users()->attach($this->user);

    $token = $this->user->createToken('team', ['actions:write', 'tenant:'.$bound->getKey()])->plainTextToken;

    $this->withToken($token)->postJson('/api/teams/bound/actions/team-note', ['title' => 'Bound'])->assertOk();
    $this->withToken($token)->postJson('/api/teams/other/actions/team-note', ['title' => 'Other'])->assertNotFound();

    expect(Post::query()->pluck('team_id')->all())->toBe([$bound->getKey()]);
});

it('refuses a guard the token reader does not know, and logs a notice naming it', function () {
    $notices = httpNotices();

    Auth::viaRequest('api-key', fn (Request $request): ?User => $request->header('X-Api-Key') === 'secret' ? User::query()->first() : null);
    config(['auth.guards.api-key' => ['driver' => 'api-key']]);

    $this->mountRoutes(fn () => Route::middleware('auth:api-key')->prefix('keyed')->name('keyed.')->group(fn () => Actions::routes()));

    $this->postJson('/keyed/actions/create-note', ['title' => 'Hi', 'body' => 'x'], ['X-Api-Key' => 'secret'])
        ->assertNotFound()
        ->assertExactJson(['message' => trans('agentic-actions::http.not_found')]);

    expect(Post::query()->count())->toBe(0)
        ->and($notices->getArrayCopy())->toBe([
            'agentic-actions: the [api-key] guard is unknown to the token reader, so every action reads as not found for its credentials. Bind AgenticActions\\Contracts\\ReadsTokenGrants to read its grants.',
        ]);
});

it('refuses a bearer token on the web mount, whose session guard never reads it', function () {
    mountWebAndApi();

    $token = $this->user->createToken('all')->plainTextToken;

    $this->withToken($token)->postJson('/actions/create-note', ['title' => 'Hi', 'body' => 'x'])->assertUnauthorized();

    expect(Post::query()->count())->toBe(0);
});
