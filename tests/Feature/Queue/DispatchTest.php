<?php

use AgenticActions\ActionContext;
use AgenticActions\Queue\RunAction;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedWrite;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Action::dispatch(): what the job captures from its caller, what chains, and what the queue stores.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
});

it('returns a PendingDispatch that chains as Laravel\'s own does, and pushes a job carrying the caller', function () {
    Queue::fake();

    $user = Queued::tokenUser(['actions:write', 'tenant:9']);
    $team = Team::factory()->create();
    $context = ActionContext::http($user, $team, 'ar')->withFixed(['slot' => 'fixed'])->withIdempotencyKey('key-1');

    $pending = QueuedWrite::dispatch(['title' => 'Queued'], $context);

    expect($pending)->toBeInstanceOf(PendingDispatch::class);

    $pending->onQueue('imports');
    unset($pending);

    Queue::assertPushedOn('imports', RunAction::class, fn (RunAction $job): bool => $job->action === QueuedWrite::class
        && $job->input === ['title' => 'Queued']
        && $job->fixed === ['slot' => 'fixed']
        && $job->actor === $user
        && $job->tenant === $team
        && $job->locale === 'ar'
        && $job->grants === ['actions:write', 'tenant:9']
        && $job->origin === 'http'
        && $job->idempotencyKey === 'key-1');
});

it('keeps no token limits for a session, the console and system work', function () {
    Queue::fake();
    Auth::shouldUse('web');

    $user = User::factory()->create();

    QueuedWrite::dispatch(['title' => 'Session'], ActionContext::http($user));
    QueuedWrite::dispatch(['title' => 'Console'], ActionContext::console($user, null, 'en', null));
    QueuedWrite::dispatch(['title' => 'System'], ActionContext::system());

    Queue::assertPushed(RunAction::class, 3);
    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->grants === null && $job->origin === 'http');
    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->grants === null && $job->origin === 'console');
    Queue::assertPushed(RunAction::class, fn (RunAction $job): bool => $job->grants === null && $job->origin === 'system' && $job->actor === null);
});

it('refuses an actor that is not an Eloquent model, and queues nothing', function () {
    Queue::fake();

    expect(fn () => QueuedWrite::dispatch(['title' => 'Nobody'], ActionContext::http(new GenericUser(['id' => 1]))))
        ->toThrow(LogicException::class, '['.QueuedWrite::class.'] cannot be queued for an actor that is not an Eloquent model: the worker restores the actor by its key.');

    Queue::assertNothingPushed();
});

it('encrypts the stored job, so the input is not readable in the queue', function () {
    Queued::onDatabase();

    QueuedWrite::dispatch(['title' => 'A secret title'], ActionContext::http(User::factory()->create()));

    $payload = (string) DB::table('jobs')->sole()->payload;
    $command = json_decode($payload, true)['data']['command'];

    expect($payload)->not->toContain('A secret title')
        ->and(json_decode($payload, true)['data']['commandName'])->toBe(RunAction::class)
        ->and(decrypt($command, false))->toContain('A secret title');
});

it('fails on an uploaded file in the input when the job is pushed', function () {
    Queued::onDatabase();

    $user = User::factory()->create();

    expect(function () use ($user): void {
        QueuedWrite::dispatch(['title' => UploadedFile::fake()->create('notes.txt')], ActionContext::http($user));
    })->toThrow(RuntimeException::class, 'Failed to serialize job of type ['.RunAction::class.']');

    expect(DB::table('jobs')->count())->toBe(0);
});
