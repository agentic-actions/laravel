<?php

use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalClaims;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\HostConfirmedTool;
use Tests\Fixtures\Approvals\HostToolAgent;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A claim is minted once, when laravel/ai reports a real pause of a call this request previewed for the same agent
 * and tool, for the person the tools act for; a resume never mints one, and a repeated call id never gets a new one.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Approvals'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Trace::reset();
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);

    $this->claimKey = fn (string $conversation, string $call = 'call_1'): string => ApprovalClaims::PREFIX.hash('sha256', $conversation."\n".$call);

    // Every claim key written, the claim itself or its marker.
    $this->claimWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event): void {
        if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
            $this->claimWrites[] = $event->key;
        }
    });

    // A turn of the author's agent in which the model asks to delete the post, paused for the person.
    $this->pause = function (?User $participant = null, ?string $conversation = null): string {
        (new ScriptedGateway([['confirmed-delete', ['post' => $this->post->id]]], 'Deleted it.', 'I will ask first.'))->install();

        $agent = new ConfirmingAgent($this->user);
        $agent = $conversation === null ? $agent->forUser($participant ?? $this->user) : $agent->continue($conversation, as: $participant ?? $this->user);
        $response = $agent->prompt('Delete my post.');

        expect($response->hasPendingApprovals())->toBeTrue();

        return $response->conversationId;
    };

    // The person's answer, resumed as production resumes it.
    $this->answer = function (string $conversation, Decision $decision): void {
        (new ConfirmingAgent($this->user))->continue($conversation, as: $this->user)->prompt(Decisions::from(['call_1' => $decision]));
    };

    // Approve call_1 again, then run it the way a resume does, asked and then handled: the package's own claim
    // check answers, whatever the resume itself decides.
    $this->confirmedAgain = function (string $conversation): string {
        rescue(fn () => ($this->answer)($conversation, Decision::approve()), report: false);

        $agent = (new ConfirmingAgent($this->user))->continue($conversation, as: $this->user);
        $tool = collect($agent->tools())->first(fn (ActionTool $tool): bool => $tool->name() === 'confirmed-delete');

        expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeInstanceOf(Approval::class);

        return $tool->handle(new Request(['post' => $this->post->id], 'call_1'));
    };
});

it('mints the claim under Octane, where the request runs in a copy of the application the dispatcher never sees', function () {
    // Octane's worker forgets the scoped instances after each request, then clones the application for the next one.
    $worker = app();
    $worker->forgetScopedInstances();
    Container::setInstance($request = clone $worker);

    try {
        $conversation = ($this->pause)();
    } finally {
        Container::setInstance($worker);
    }

    expect($this->claimWrites)->toBe([($this->claimKey)($conversation)])
        ->and(Cache::has(($this->claimKey)($conversation)))->toBeTrue()
        ->and($request->make(PendingCards::class)->has('call_1'))->toBeFalse();

    Exceptions::assertNothingReported();
});

it('runs the confirmed call once on its claim, and a resume mints nothing', function () {
    $conversation = ($this->pause)();
    $this->claimWrites = [];

    ($this->answer)($conversation, Decision::approve());

    expect(Post::query()->whereKey($this->post->id)->exists())->toBeFalse()
        ->and(array_count_values(Trace::$calls)['ConfirmedDelete::handle'] ?? 0)->toBe(1)
        ->and($this->claimWrites)->toBe([($this->claimKey)($conversation).':claimed'])
        ->and(Cache::has(($this->claimKey)($conversation)))->toBeFalse();
});

it('mints nothing, runs nothing and streams the refused row instead of the card when the conversation\'s participant is not the person the tools act for', function () {
    $someoneElse = User::factory()->create();
    (new ScriptedGateway([['confirmed-delete', ['post' => $this->post->id]]]))->install();

    $parts = Parts::of(Parts::body((new ConfirmingAgent($this->user))->forUser($someoneElse)->stream('Delete my post.')));

    expect(collect($parts)->where('id', 'approval:call_1')->all())->toBe([])
        ->and(collect($parts)->firstWhere('id', 'a:call_1'))->toBe([
            'type' => 'data-action',
            'id' => 'a:call_1',
            'data' => [
                'action' => 'confirmed-delete',
                'label' => 'Delete this post? This cannot be undone.',
                'status' => 'refused',
                'effect' => 'destructive',
                'note' => trans('agentic-actions::activity.refused'),
            ],
        ])
        ->and($this->claimWrites)->toBe([])
        ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();

    Exceptions::assertNothingReported();
});

it('mints nothing for a pause whose conversation is unknown, and never keeps the card', function () {
    $agent = (new ConfirmingAgent($this->user))->forUser($this->user);
    $tool = collect($agent->tools())->first(fn (ActionTool $tool): bool => $tool->name() === 'confirmed-delete');

    expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeInstanceOf(Approval::class);

    event(new ToolApprovalRequested('invocation-1', $agent, collect([new PendingApproval('call_1', 'confirmed-delete', ['post' => $this->post->id])]), null, $this->user));

    expect($this->claimWrites)->toBe([])
        ->and(app(PendingCards::class)->has('call_1'))->toBeFalse();

    Exceptions::assertNothingReported();
});

it('mints nothing for another agent\'s call or another tool of the same id, and keeps the card for its own', function () {
    $agent = (new ConfirmingAgent($this->user))->forUser($this->user);
    $tool = collect($agent->tools())->first(fn (ActionTool $tool): bool => $tool->name() === 'confirmed-delete');
    $tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1'));

    $sameAgentOtherTool = collect([new PendingApproval('call_1', 'confirmed-send', ['to' => 'x'])]);
    $otherAgent = (new ConfirmingAgent($this->user))->forUser($this->user);

    event(new ToolApprovalRequested('invocation-1', $agent, $sameAgentOtherTool, 'conversation-1', $this->user));
    event(new ToolApprovalRequested('invocation-2', $otherAgent, collect([new PendingApproval('call_1', 'confirmed-delete', [])]), 'conversation-1', $this->user));

    expect($this->claimWrites)->toBe([])
        ->and(app(PendingCards::class)->has('call_1'))->toBeTrue();
});

it('gives a host tool\'s pause no card and no claim, and raises nothing', function () {
    (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'x']]]))->install();

    $parts = Parts::of(Parts::body((new HostToolAgent($this->user))->forUser($this->user)->stream('Send a note.')));

    expect($this->claimWrites)->toBe([])
        ->and(collect($parts)->whereIn('id', ['approval:call_1', 'a:call_1'])->all())->toBe([]);

    Exceptions::assertNothingReported();
});

it('gives a host tool\'s pause no card or claim when its call id collides with a previewed call, and keeps that card', function () {
    $agent = (new ConfirmingAgent($this->user))->forUser($this->user);
    $tool = collect($agent->tools())->first(fn (ActionTool $tool): bool => $tool->name() === 'confirmed-delete');

    expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeInstanceOf(Approval::class);

    (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'x']]]))->install();
    $parts = Parts::of(Parts::body((new HostToolAgent($this->user))->forUser($this->user)->stream('Send a note.')));

    expect($this->claimWrites)->toBe([])
        ->and(collect($parts)->whereIn('id', ['approval:call_1', 'a:call_1'])->all())->toBe([])
        ->and(app(PendingCards::class)->has('call_1'))->toBeTrue();

    Exceptions::assertNothingReported();
});

it('mints nothing new when a later pause reuses the call id, and spends the old claim', function () {
    $conversation = ($this->pause)();

    // The person writes instead of answering; the model asks again with the same call id.
    ($this->pause)(conversation: $conversation);

    expect($this->claimWrites)->toBe([($this->claimKey)($conversation), ($this->claimKey)($conversation).':claimed'])
        ->and(Cache::has(($this->claimKey)($conversation)))->toBeFalse()
        ->and(($this->confirmedAgain)($conversation))->toBe(trans('agentic-actions::model.not_confirmed'))
        ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue()
        ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle');
});

it('runs nothing when, after a decline, the model repeats the call id with the same input', function () {
    $conversation = ($this->pause)();

    // What ChatRequest does for a declined call, then the resume: the model reads the decline and asks again.
    app(ApprovalClaims::class)->burn($conversation, 'call_1');
    (new ScriptedGateway([['confirmed-delete', ['post' => $this->post->id]]]))->install();
    $repeated = (new ConfirmingAgent($this->user))->continue($conversation, as: $this->user)
        ->prompt(Decisions::from(['call_1' => Decision::reject('No.')]));

    expect($repeated->hasPendingApprovals())->toBeTrue()
        ->and(Cache::has(($this->claimKey)($conversation)))->toBeFalse()
        ->and(($this->confirmedAgain)($conversation))->toBe(trans('agentic-actions::model.not_confirmed'))
        ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue()
        ->and(Trace::$calls)->not->toContain('ConfirmedDelete::handle');
});
