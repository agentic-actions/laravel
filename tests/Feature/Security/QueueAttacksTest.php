<?php

namespace Tests\Feature\Security;

use AgenticActions\ActionContext;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Facades\Actions;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Queue\RunAction;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use stdClass;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Queue\QueuedDestructive;
use Tests\Fixtures\Queue\QueuedWrite;
use Tests\Fixtures\Queue\ReadThatQueues;
use Tests\Fixtures\Queue\Recorder;
use Tests\Fixtures\Security\ExternalPing;
use Tests\Fixtures\Security\Inside;
use Tests\Fixtures\Security\NestingRead;
use Tests\Fixtures\Security\NestingWrite;
use Tests\Fixtures\Security\ReadQueuesRead;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A hostile queued-job author. The job's own code may build any context it likes, queue more jobs, or be a Read: a
 * queued run still never acts with more than the call that queued it, stops with the incident switch of the surface
 * that queued it, and a Read never queues a write, in the request or in the worker.
 */

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    Auth::shouldUse('web');
    Recorder::reset();
    Inside::reset();
    ReadThatQueues::$queues = QueuedWrite::class;
    Queued::onDatabase();

    $this->user = User::factory()->create();
});

afterEach(function () {
    Inside::reset();
    ReadThatQueues::$queues = QueuedWrite::class;
});

/**
 * Queue NestingWrite as the given caller, then run it in a worker, where its handle() runs $inside. "mcp" queues it
 * while an MCP request is handled, with a token; "token" from an HTTP request with a token; "session" with a session.
 *
 * @param  Closure(ActionContext): mixed  $inside
 * @param  list<string>  $abilities
 */
function queueNestingAs(string $caller, Closure $inside, array $abilities = ['actions:write']): void
{
    Inside::$run = $inside;

    $user = $caller === 'session' ? test()->user : Queued::tokenUser($abilities, test()->user);

    if ($caller === 'mcp') {
        app()->instance('mcp.request', new stdClass);
    }

    try {
        NestingWrite::dispatch([], ActionContext::http($user));
    } finally {
        app()->forgetInstance('mcp.request');
    }

    // The worker has no request: no token, and the session guard as its default.
    Auth::forgetGuards();
    Auth::shouldUse('web');

    Queued::work();
}

describe('a queued run never acts with more than its caller had', function () {
    it('refuses Destructive and External actions that a job queued inside MCP runs, whatever context its code builds', function () {
        queueNestingAs('mcp', fn (ActionContext $context): array => [
            Actions::attempt(QueuedDestructive::class, [], ActionContext::http($context->actor()))->kind(),
            Actions::attempt(ExternalPing::class, [], ActionContext::http($context->actor()))->kind(),
            Actions::attempt(QueuedDestructive::class, [], ActionContext::system())->kind(),
            Actions::attempt(QueuedDestructive::class, [], $context)->kind(),
        ], ['actions:write', 'actions:destructive', 'actions:external']);

        // The token grants both abilities, so only the model's origin refuses each call.
        expect(Inside::$results)->toBe([[OutcomeKind::NotFound, OutcomeKind::NotFound, OutcomeKind::NotFound, OutcomeKind::NotFound]])
            ->and(Recorder::$runs)->toBe([]);
    });

    it('keeps a token\'s grants for a call a queued job makes with a context its code builds', function () {
        queueNestingAs('token', fn (ActionContext $context): array => [
            Actions::attempt(QueuedDestructive::class, [], ActionContext::http($context->actor()))->kind(),
            Actions::attempt(QueuedDestructive::class, [], ActionContext::agent($context->actor()))->kind(),
            Actions::attempt(QueuedWrite::class, ['title' => 'Allowed'], ActionContext::http($context->actor()))->kind(),
        ]);

        expect(Inside::$results)->toBe([[OutcomeKind::NotFound, OutcomeKind::NotFound, OutcomeKind::Ok]])
            ->and(Post::query()->pluck('title')->all())->toBe(['Allowed']);
    });

    it('keeps a session\'s full access for a call its queued job makes, as the session had it', function () {
        queueNestingAs('session', fn (ActionContext $context): OutcomeKind => Actions::attempt(QueuedDestructive::class, [], ActionContext::http($context->actor()))->kind());

        expect(Inside::$results)->toBe([OutcomeKind::Ok])
            ->and(Recorder::$runs)->toHaveCount(1);
    });

    it('queues from inside a queued run with that run\'s origin and grants, whatever context its code builds', function () {
        $captured = fn (ActionContext $context): array => [
            RunAction::capture(QueuedWrite::class, ['title' => 'Next'], ActionContext::http($context->actor()))->origin,
            RunAction::capture(QueuedWrite::class, ['title' => 'Next'], ActionContext::http($context->actor()))->grants,
        ];

        queueNestingAs('mcp', $captured, ['*', 'actions:write']);
        queueNestingAs('token', $captured, ['*']);

        expect(Inside::$results)->toBe([
            ['mcp', ['actions:write']],
            ['http', ['*']],
        ]);
    });
});

describe('the incident switch', function () {
    it('refuses a job that an MCP-queued job queued with a context its code built, once surfaces.mcp is off', function () {
        queueNestingAs('mcp', function (ActionContext $context): null {
            QueuedWrite::dispatch(['title' => 'Grandchild'], ActionContext::http($context->actor()));

            return null;
        });

        expect(DB::table('jobs')->count())->toBe(1);

        config(['agentic-actions.surfaces.mcp' => false]);
        Queued::work();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(Recorder::$runs)->toBe([])
            ->and(Post::query()->count())->toBe(0);
    });
});

describe('a Read never queues a write', function () {
    it('refuses a Write queued from any hook of a Read, and queues nothing', function (string $hook) {
        Queue::fake();
        Exceptions::fake();
        Inside::$hook = $hook;
        Inside::$run = fn (ActionContext $context): mixed => QueuedWrite::dispatch(['title' => 'From '.$hook], $context);

        $outcome = Actions::attempt(NestingRead::class, [], ActionContext::http($this->user));

        expect($outcome->kind())->toBe(OutcomeKind::Failed)
            ->and($outcome->exception())->toBeInstanceOf(ReadActionWrote::class);

        Queue::assertNothingPushed();
    })->with(['authorize', 'prepareForValidation', 'handle', 'modelReply']);

    it('refuses a Write queued by a Write that a Read runs in-process', function () {
        Queue::fake();
        Exceptions::fake();

        $depth = 0;
        Inside::$run = function (ActionContext $context) use (&$depth): mixed {
            if ($depth++ === 0) {
                return Actions::attempt(NestingWrite::class, [], $context)->kind();
            }

            return QueuedWrite::dispatch(['title' => 'Two hops'], $context);
        };

        expect(Actions::attempt(NestingRead::class, [], ActionContext::http($this->user))->kind())->toBe(OutcomeKind::Ok)
            ->and(Inside::$results)->toBe([OutcomeKind::Failed]);

        Queue::assertNothingPushed();
        Exceptions::assertReported(ReadActionWrote::class);
    });

    it('refuses, in the worker, a Write queued by a Read that another Read queued', function () {
        Exceptions::fake();

        ReadQueuesRead::dispatch([], ActionContext::http($this->user));

        Queued::work();

        expect(DB::table('jobs')->count())->toBe(1);

        Queued::work();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(Recorder::$runs)->toBe([])
            ->and(Post::query()->count())->toBe(0);

        Exceptions::assertReported(ReadActionWrote::class);
    });
});
