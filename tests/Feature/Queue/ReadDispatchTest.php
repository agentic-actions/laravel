<?php

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Queue\RunAction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedRead;
use Tests\Fixtures\Queue\QueuedWrite;
use Tests\Fixtures\Queue\ReadThatQueues;
use Tests\Fixtures\Queue\Recorder;
use Workbench\App\Models\User;

/*
 * While a Read's own code runs, dispatch() refuses an action that is not a Read, before anything is queued. The
 * rule is part of the Read guard.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Auth::shouldUse('web');
    Recorder::reset();
    ReadThatQueues::$queues = QueuedWrite::class;

    $this->context = ActionContext::http(User::factory()->create());
});

afterEach(function () {
    ReadThatQueues::$queues = QueuedWrite::class;
});

it('ends a Read that queues a Write as Failed, reports ReadActionWrote, and queues nothing', function () {
    Queue::fake();
    Exceptions::fake();

    $outcome = app(ActionsManager::class)->attempt(ReadThatQueues::class, [], $this->context);

    expect($outcome->kind())->toBe(OutcomeKind::Failed)
        ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class)
        ->and($outcome->exception()?->getMessage())->toBe('A Read action tried to queue ['.QueuedWrite::class.'], which is not a Read. Nothing was queued: give the calling action a writing effect.');

    Queue::assertNothingPushed();
    Exceptions::assertReported(ReadActionWrote::class);
    Exceptions::assertReportedCount(1);
});

it('lets a Read queue a Read, which the guard lets into the queue table', function () {
    Queued::onDatabase();
    ReadThatQueues::$queues = QueuedRead::class;

    expect(ReadThatQueues::run([], $this->context))->toBe('queued')
        ->and(DB::table('jobs')->count())->toBe(1);

    Queued::work();

    expect(Recorder::$runs)->toHaveCount(1);
});

it('lets a listener of the Read\'s ActionCompleted queue a Write: that runs after the Read', function () {
    Queue::fake();

    Event::listen(ActionCompleted::class, fn (ActionCompleted $event) => $event->class === QueuedRead::class
        ? QueuedWrite::dispatch(['title' => 'From a listener'], $this->context)
        : null);

    expect(QueuedRead::run([], $this->context))->toBe('read');

    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->action === QueuedWrite::class);
});

it('lets a Read queue a Write with reads.guard off: the rule is part of the guard', function () {
    Queue::fake();
    config(['agentic-actions.reads.guard' => false]);

    expect(ReadThatQueues::run([], $this->context))->toBe('queued');

    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->action === QueuedWrite::class);
});

it('lets code outside a Read queue a Write', function () {
    Queue::fake();

    QueuedWrite::dispatch(['title' => 'Outside'], $this->context);
    QueuedRead::run([], $this->context);
    QueuedWrite::dispatch(['title' => 'After'], $this->context);

    Queue::assertPushed(RunAction::class, 2);
});
