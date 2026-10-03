<?php

use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Queue\RunAction;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Gateway\ParentInvocation;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedDestructive;
use Tests\Fixtures\Queue\QueuedWrite;
use Tests\Fixtures\Queue\Recorder;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A queued run keeps its caller's origin: a job queued by a model stays model-driven in the worker, a job queued by
 * system work stays system work, and the incident switches reach jobs their surface queued.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Auth::shouldUse('web');
    Recorder::reset();
    Queued::onDatabase();
    Event::fake([ActionCompleted::class, ActionRefused::class]);

    $this->user = User::factory()->create();
});

/**
 * Queue an action while an MCP request is being handled, then leave it.
 *
 * @param  Closure(): mixed  $dispatch
 */
function queuedInsideMcp(Closure $dispatch): void
{
    app()->instance('mcp.request', new stdClass);

    try {
        $dispatch();
    } finally {
        app()->forgetInstance('mcp.request');
    }
}

it('keeps a model origin for a job queued inside an MCP request, so a Destructive action is refused', function () {
    // The token grants the destructive ability, so only the model's origin refuses the run in the worker.
    $user = Queued::tokenUser(['actions:read', 'actions:write', 'actions:destructive'], $this->user);

    queuedInsideMcp(fn () => QueuedDestructive::dispatch([], ActionContext::http($user)));

    Auth::forgetGuards();
    Auth::shouldUse('web');

    Queued::work();

    expect(Recorder::$runs)->toBe([]);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue && $event->modelDriven);
});

it('keeps a model origin for a job queued inside a laravel/ai tool call, so a Destructive action is refused', function () {
    $this->skipUnlessAi();

    ParentInvocation::within('invocation-1', 'tool-invocation-1', fn () => QueuedDestructive::dispatch([], ActionContext::http($this->user)));

    Queued::work();

    expect(Recorder::$runs)->toBe([]);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->modelDriven);
});

it('runs the same Destructive action queued from an HTTP session', function () {
    QueuedDestructive::dispatch([], ActionContext::http($this->user));

    Queued::work();

    expect(Recorder::sole()->origin)->toBe(Surface::Http);

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->surface === Surface::Queue && ! $event->modelDriven);
});

it('runs a job queued from system work as system work, with no actor', function () {
    $team = Team::factory()->create();

    QueuedWrite::dispatch(['title' => 'Scheduled'], ActionContext::system($team));

    Queued::work();

    $context = Recorder::sole();

    expect($context->surface)->toBe(Surface::Queue)
        ->and($context->origin)->toBe(Surface::System)
        ->and($context->actor)->toBeNull()
        ->and($context->tenant?->is($team))->toBeTrue()
        ->and($context->isSystem())->toBeTrue()
        ->and($context->actorKey())->toBe('system');
});

it('never runs a job queued with no actor by any other caller as system work: its tenant refuses it', function () {
    $team = Team::factory()->create();

    QueuedWrite::dispatch(['title' => 'Unattended'], ActionContext::http(null, $team));

    Queued::work();

    expect(Recorder::$runs)->toBe([]);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue);
});

it('keeps the origin of a job queued from a queued run', function () {
    Queue::fake();

    $context = ActionContext::queued(Surface::Mcp, $this->user, null, 'en', [], ['actions:write'], null);

    QueuedWrite::dispatch(['title' => 'Again'], $context);

    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->origin === 'mcp' && $job->grants === ['actions:write']);
});

it('refuses a job MCP queued once surfaces.mcp is off, and runs it when it is back on, whatever surfaces.agents says', function () {
    $user = Queued::tokenUser(['actions:write'], $this->user);

    queuedInsideMcp(function () use ($user): void {
        QueuedWrite::dispatch(['title' => 'First'], ActionContext::http($user));
        QueuedWrite::dispatch(['title' => 'Second'], ActionContext::http($user));
    });

    Auth::forgetGuards();

    config(['agentic-actions.surfaces.mcp' => false]);
    Queued::work();

    expect(Post::query()->count())->toBe(0);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue);

    config(['agentic-actions.surfaces.mcp' => true, 'agentic-actions.surfaces.agents' => false]);
    Queued::work();

    expect(Post::query()->sole()->title)->toBe('Second');
});

it('refuses a job an agent\'s tool queued once surfaces.agents is off, and runs it when it is back on', function () {
    $this->skipUnlessAi();

    ParentInvocation::within('invocation-1', 'tool-invocation-1', function (): void {
        QueuedWrite::dispatch(['title' => 'First'], ActionContext::http($this->user));
        QueuedWrite::dispatch(['title' => 'Second'], ActionContext::http($this->user));
    });

    config(['agentic-actions.surfaces.agents' => false]);
    Queued::work();

    expect(Post::query()->count())->toBe(0);

    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue);

    config(['agentic-actions.surfaces.agents' => true]);
    Queued::work();

    expect(Post::query()->sole()->title)->toBe('Second');
});

it('leaves a job queued from HTTP running when both model switches are off', function () {
    config(['agentic-actions.surfaces.mcp' => false, 'agentic-actions.surfaces.agents' => false]);

    QueuedWrite::dispatch(['title' => 'Web'], ActionContext::http($this->user));

    Queued::work();

    expect(Post::query()->sole()->title)->toBe('Web');
});
