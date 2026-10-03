<?php

use AgenticActions\ActionContext;
use AgenticActions\ActionsManager;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Surface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedCrash;
use Tests\Fixtures\Queue\QueuedKeyed;
use Tests\Fixtures\Queue\QueuedWrite;
use Tests\Fixtures\Queue\Recorder;
use Tests\Fixtures\Queue\TrashableTeam;
use Tests\Fixtures\Queue\TrashableUser;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * RunAction in the worker: the caller rebuilt on the Queue surface, the fixed overlay, membership again, deleted and
 * soft-deleted callers, crashes, refusals and the idempotency key.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Auth::shouldUse('web');
    Recorder::reset();

    $this->user = User::factory()->create();
});

it('runs on the Queue surface after a serialize round trip, with the actor loaded fresh and its own request id', function () {
    Event::fake([ActionCompleted::class]);

    $caller = ActionContext::http($this->user, null, 'ar');

    QueuedWrite::dispatch(['title' => 'Round trip'], $caller);

    $context = Recorder::sole();

    expect($context->surface)->toBe(Surface::Queue)
        ->and($context->origin)->toBe(Surface::Http)
        ->and($context->actor)->not->toBe($this->user)
        ->and($context->actor)->toBeInstanceOf(User::class)
        ->and($context->actor?->getAuthIdentifier())->toBe($this->user->getKey())
        ->and($context->locale)->toBe('ar')
        ->and($context->guard)->toBeNull()
        ->and($context->requestId)->not->toBe($caller->requestId)
        ->and(Post::query()->sole()->title)->toBe('Round trip');

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->surface === Surface::Queue
        && ! $event->modelDriven
        && $event->actorId === $this->user->getKey()
        && $event->requestId === $context->requestId);
});

it('reads the actor as it is when the job runs, not as it was queued', function () {
    Queued::onDatabase();

    QueuedWrite::dispatch(['title' => 'Later'], ActionContext::http($this->user));

    $this->user->forceFill(['name' => 'Renamed'])->save();

    Queued::work();

    expect(Recorder::sole()->actor?->getAttribute('name'))->toBe('Renamed');
});

it('overlays the caller\'s fixed input as the direct run does', function () {
    $context = ActionContext::http($this->user)->withFixed(['slot' => 'fixed', 'stray' => 'dropped']);
    $input = ['title' => 'Fixed', 'slot' => 'from the caller', 'stray' => 'from the caller'];

    QueuedWrite::run($input, $context);
    QueuedWrite::dispatch($input, $context);

    expect(Recorder::$runs)->toHaveCount(2)
        ->and(Recorder::$runs[1]['input'])->toBe(Recorder::$runs[0]['input'])
        ->and(Recorder::$runs[1]['input'])->toBe(['title' => 'Fixed', 'slot' => 'fixed'])
        ->and(Recorder::$runs[1]['context']->fixed)->toBe(['slot' => 'fixed', 'stray' => 'dropped']);
});

it('checks membership again, so a person removed from the tenant is refused and the job ends', function () {
    $this->useTeamTenancy();
    Queued::onDatabase();
    Event::fake([ActionCompleted::class, ActionRefused::class]);

    $team = Team::factory()->create();
    $team->users()->attach($this->user);

    QueuedWrite::dispatch(['title' => 'Team'], ActionContext::http($this->user, $team));

    $team->users()->detach($this->user);

    Queued::work();

    expect(Post::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Event::assertNotDispatched(ActionCompleted::class);
    Event::assertDispatched(ActionRefused::class, fn (ActionRefused $event): bool => $event->reason === 'not_found' && $event->surface === Surface::Queue);
});

it('drops the job of a deleted actor without running it or failing', function () {
    Queued::onDatabase();
    Event::fake([ActionCompleted::class, ActionRefused::class, ActionFailed::class]);

    QueuedWrite::dispatch(['title' => 'Gone'], ActionContext::http($this->user));

    $this->user->delete();

    Queued::work();

    expect(Recorder::$runs)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Event::assertNothingDispatched();
});

describe('a soft-deleted caller', function () {
    beforeEach(function () {
        // The workbench tables have no deleted_at column; this test's transaction adds one and rolls it back.
        Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('teams', fn (Blueprint $table) => $table->softDeletes());

        Queued::onDatabase();
        app(ActionsManager::class)->membershipUsing(fn (): bool => true);
        Event::fake([ActionCompleted::class, ActionRefused::class, ActionFailed::class]);

        $this->person = TrashableUser::query()->findOrFail($this->user->getKey());
        $this->team = TrashableTeam::query()->findOrFail(Team::factory()->create()->getKey());
    });

    it('runs while the person and the tenant are there', function () {
        QueuedWrite::dispatch(['title' => 'Kept'], ActionContext::http($this->person, $this->team));

        Queued::work();

        expect(Post::query()->sole()->title)->toBe('Kept');

        Event::assertDispatched(ActionCompleted::class);
    });

    it('drops the job of a soft-deleted person or tenant without running it', function (string $caller) {
        QueuedWrite::dispatch(['title' => 'Trashed'], ActionContext::http($this->person, $this->team));

        $this->{$caller}->delete();

        Queued::work();

        expect(Recorder::$runs)->toBe([])
            ->and(Post::query()->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        Event::assertNothingDispatched();
    })->with(['person', 'team']);
});

it('fails the job the Laravel way after ActionFailed when the action crashes', function () {
    Queued::onDatabase();
    Exceptions::fake();
    Event::fake([ActionFailed::class]);

    QueuedCrash::dispatch([], ActionContext::http($this->user));

    Queued::work();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and((string) DB::table('failed_jobs')->sole()->exception)->toContain('The queued action crashed.');

    Event::assertDispatchedTimes(ActionFailed::class, 1);
    Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event): bool => $event->surface === Surface::Queue && $event->exceptionClass === RuntimeException::class);
    Exceptions::assertReportedCount(1);
});

it('keeps the caller\'s raw key, so the namespaced key equals the direct call\'s', function () {
    $context = ActionContext::http($this->user)->withIdempotencyKey('import-1');

    $direct = QueuedKeyed::run([], $context);

    QueuedKeyed::dispatch([], $context);

    expect(Recorder::$runs)->toHaveCount(2)
        ->and(Recorder::$runs[1]['context']->surface)->toBe(Surface::Queue)
        ->and(Recorder::$runs[1]['input']['key'])->toBe($direct);
});
