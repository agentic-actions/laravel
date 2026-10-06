<?php

use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Refusal;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Gateway\ParentInvocation;
use Tests\Fixtures\Queue\OwnJob;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedDestructive;
use Tests\Fixtures\Queue\QueuedRead;
use Tests\Fixtures\Queue\Recorder;
use Tests\Fixtures\Security\Inside;
use Tests\Fixtures\Security\NestingWrite;
use Workbench\App\Models\User;

/*
 * A job of an app's own runs actions with run() and the context it builds with ActionContext::http(). In the worker it
 * keeps nothing of whoever queued it, neither a token's limits nor a model's: under the session guard each call has the
 * person's full access, and under a token guard, which holds no token there, each call reads as not found.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Recorder::reset();
    Inside::reset();
    OwnJob::$refusals = [];
    Queued::onDatabase();
    Event::fake([ActionCompleted::class, ActionRefused::class]);
});

afterEach(function () {
    Inside::reset();
});

it('runs a Destructive call as the person under the session guard, never model-driven, whatever queued the job', function (string $queuedBy) {
    if ($queuedBy === 'a laravel/ai tool call') {
        $this->skipUnlessAi();
    }

    // The token cannot destroy, and a model never reaches a Destructive action: either limit would refuse the call.
    $user = Queued::tokenUser(['actions:read', 'actions:write']);
    $queue = function () use ($user): void {
        OwnJob::dispatch($user, QueuedDestructive::class);
    };
    $insideMcp = function (Closure $dispatch): void {
        app()->instance('mcp.request', new stdClass);

        try {
            $dispatch();
        } finally {
            app()->forgetInstance('mcp.request');
        }
    };

    match ($queuedBy) {
        'an MCP request' => $insideMcp($queue),
        'a laravel/ai tool call' => ParentInvocation::within('invocation-1', 'tool-invocation-1', $queue),
        'a queued run MCP queued' => $insideMcp(function () use ($user): void {
            Inside::$run = function (ActionContext $context): null {
                OwnJob::dispatch($context->actor(User::class), QueuedDestructive::class);

                return null;
            };

            NestingWrite::dispatch([], ActionContext::http($user));
        }),
    };

    // The worker has no request: no token, and the session guard as its default.
    Auth::forgetGuards();
    Auth::shouldUse('web');

    if ($queuedBy === 'a queued run MCP queued') {
        Queued::work();
    }

    Queued::work();

    expect(Recorder::sole()->surface)->toBe(Surface::Http)
        ->and(OwnJob::$refusals)->toBe([]);

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->class === QueuedDestructive::class && $event->surface === Surface::Http && ! $event->modelDriven);
})->with([
    'an MCP request',
    'a laravel/ai tool call',
    'a queued run MCP queued',
]);

it('runs each call under the session guard, and reads it as not found under a token guard, which holds no token in the worker', function (string $guard, bool $runs) {
    OwnJob::dispatch(User::factory()->create(), QueuedRead::class);

    // The worker's default guard, as auth.defaults.guard names it.
    Auth::forgetGuards();
    Auth::shouldUse($guard);

    Queued::work();

    expect(Recorder::$runs)->toHaveCount($runs ? 1 : 0)
        ->and(array_map(fn (Refusal $refusal): int => $refusal->statusCode(), OwnJob::$refusals))->toBe($runs ? [] : [404]);

    if (! $runs) {
        Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Http && ! $event->modelDriven);
    }
})->with([
    'the session guard' => ['web', true],
    'a token guard' => ['sanctum', false],
]);
