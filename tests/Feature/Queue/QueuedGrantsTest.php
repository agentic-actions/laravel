<?php

use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedTeamWrite;
use Tests\Fixtures\Queue\QueuedWrite;
use Tests\Fixtures\Queue\Recorder;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;

/*
 * A queued run keeps the grants its caller's token had at dispatch, and the worker decides on them.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Recorder::reset();
    Event::fake([ActionCompleted::class, ActionRefused::class]);
});

/**
 * Queue a Write for a token with these abilities, then run it in the worker after the request is over: the grants
 * travel in the job, never the token.
 *
 * @param  list<string>  $abilities
 */
function queuedGrantsWrite(array $abilities): void
{
    Queued::onDatabase();

    QueuedWrite::dispatch(['title' => 'Granted'], ActionContext::http(Queued::tokenUser($abilities)));

    Auth::forgetGuards();
    Auth::shouldUse('web');

    Queued::work();
}

it('refuses a Write in the worker for a token that can only read', function () {
    queuedGrantsWrite(['actions:read']);

    expect(Post::query()->count())->toBe(0);

    Event::assertNotDispatched(ActionCompleted::class);
    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue);
});

it('runs a Write for a token that can write, and for "*" captured on HTTP, as HTTP counts it', function (array $abilities) {
    queuedGrantsWrite($abilities);

    expect(Post::query()->sole()->title)->toBe('Granted');

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->surface === Surface::Queue);
})->with([
    'actions:write' => [['actions:write']],
    '"*"' => [['*']],
]);

it('refuses in the worker a Write whose "*" was captured inside MCP', function () {
    Queued::onDatabase();
    app()->instance('mcp.request', new stdClass);

    QueuedWrite::dispatch(['title' => 'Inside MCP'], ActionContext::http(Queued::tokenUser(['*'])));

    app()->forgetInstance('mcp.request');
    Auth::forgetGuards();

    Queued::work();

    expect(Post::query()->count())->toBe(0);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue && $event->modelDriven);
});

it('compares a tenant: grant with the restored tenant', function (bool $bound, int $posts) {
    $this->useTeamTenancy();
    Queued::onDatabase();

    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $user = Queued::tokenUser(['actions:write', 'tenant:'.($bound ? $team : $other)->getKey()]);
    $team->users()->attach($user);
    $other->users()->attach($user);

    QueuedTeamWrite::dispatch(['title' => 'Bound'], ActionContext::http($user, $team));
    Auth::forgetGuards();

    Queued::work();

    expect(Post::query()->where('team_id', $team->getKey())->count())->toBe($posts);
})->with([
    'bound to the restored tenant' => [true, 1],
    'bound to another tenant' => [false, 0],
]);

it('keeps a tenant-bound token off an account-level action in the worker, as on every door', function () {
    $this->useTeamTenancy();
    Queued::onDatabase();

    $team = Team::factory()->create();
    $user = Queued::tokenUser(['actions:write', 'tenant:'.$team->getKey()]);
    $team->users()->attach($user);

    QueuedWrite::dispatch(['title' => 'Account level'], ActionContext::http($user, $team));
    Auth::forgetGuards();

    Queued::work();

    expect(Post::query()->count())->toBe(0);
});
