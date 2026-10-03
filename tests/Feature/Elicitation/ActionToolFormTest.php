<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Elicitation\Form;
use AgenticActions\Exposure\ClassExposure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingDelete;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingRules;
use Tests\Fixtures\Elicitation\NotAsking;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * ActionTool for an asking action: an incomplete call pauses with the form's sentence and remembers the
 * form; the description tells the model it may call with fields left out; an answered call runs the person's values
 * over the model's arguments, on a ticket carrying the verified form, and the model reads only which fields were
 * filled. Destructive and External tools never ask.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();
    AskingRules::$handled = null;
    AskingDelete::$handled = false;
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->agent = (new AskingAgent($this->user))->continue('conversation-1', as: $this->user);

    // A tool as ToolFactory builds it: the agent only when the action can pause.
    $this->tool = fn (string $class, bool $pausing = true): ActionTool => new ActionTool(
        ClassExposure::of($class),
        ActionContext::agent($this->user),
        ['asking'],
        $pausing ? $this->agent : null,
    );

    $this->claimKey = fn (string $call = 'call_1'): string => ApprovalClaims::PREFIX.hash('sha256', "conversation-1\n".$call);

    // A pause, as laravel/ai asks for it and PendingCards mints it; then the answer ChatRequest verifies.
    $this->paused = function (string $class, array $arguments, string $call = 'call_1'): Form {
        $tool = ($this->tool)($class);

        expect($tool->shouldRequestApproval(new Request($arguments, $call)))->toBeInstanceOf(Approval::class);

        $form = $tool->card($call, $arguments);

        expect(app(ApprovalClaims::class)->mint('conversation-1', $call, $form))->toBeTrue();

        return $form;
    };
});

describe('the pause', function () {
    it('asks with the form\'s sentence and remembers the form for the call, running nothing', function () {
        $approval = ($this->tool)(AskingDraft::class)->shouldRequestApproval(new Request(['title' => 'Launch notes'], 'call_1'));

        expect($approval)->toBeInstanceOf(Approval::class)
            ->and($approval->reason)->toBe('A few details for your post.')
            ->and(app(PendingCards::class)->has('call_1'))->toBeTrue()
            ->and(AskingDraft::$handled)->toBeNull()
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();
    });

    it('never asks for a complete call, an action that does not ask, a Destructive one, a tool without an agent or a call without an id', function (string $class, array $arguments, bool $pausing, ?string $call) {
        expect(($this->tool)($class, $pausing)->shouldRequestApproval(new Request($arguments, $call)))->toBeNull()
            ->and(app(PendingCards::class)->has('call_1'))->toBeFalse();
    })->with([
        'a complete call' => [AskingDraft::class, ['title' => 'x', 'body' => 'x', 'status' => 'draft'], true, 'call_1'],
        'an action that does not ask' => [NotAsking::class, [], true, 'call_1'],
        'a Destructive action with $askForMissing' => [AskingDelete::class, [], true, 'call_1'],
        'a tool built without an agent' => [AskingDraft::class, ['title' => 'x'], false, 'call_1'],
        'a null id' => [AskingDraft::class, ['title' => 'x'], true, null],
        'a blank id' => [AskingDraft::class, ['title' => 'x'], true, '  '],
    ]);

    it('keeps asking when an agent tells it to go without, and still refuses to be told to ask', function () {
        $tool = ($this->tool)(AskingDraft::class);

        expect($tool->withoutApproval())->toBe($tool)
            ->and($tool->shouldRequestApproval(new Request(['title' => 'x'], 'call_1')))->toBeInstanceOf(Approval::class)
            ->and(fn () => $tool->requireApproval('Please ask.'))->toThrow(LogicException::class, '[asking-draft] is a write action');
    });
});

describe('the description', function () {
    it('tells the model it may call with fields left out, only on a tool that can ask', function () {
        $asks = trans('agentic-actions::model.asks');

        expect(($this->tool)(AskingDraft::class)->description())->toBe('Draft a post for the signed-in author. '.$asks)
            ->and(($this->tool)(AskingDraft::class, pausing: false)->description())->toBe('Draft a post for the signed-in author.')
            ->and(($this->tool)(NotAsking::class)->description())->not->toContain($asks)
            ->and(($this->tool)(AskingDelete::class)->description())->not->toContain($asks);
    });

    it('says it in the context\'s locale', function () {
        $tool = new ActionTool(ClassExposure::of(AskingDraft::class), ActionContext::agent($this->user, locale: 'ar'), ['asking'], $this->agent);

        expect($tool->description())->toEndWith(trans('agentic-actions::model.asks', [], 'ar'));
    });
});

describe('the answer', function () {
    it('runs the person\'s values over the model\'s arguments, once, and the model reads only which fields were filled', function () {
        $form = ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);
        app(PendingCards::class)->answer('call_1', $form, ['title' => 'Launch notes', 'body' => 'CANARY-BODY', 'status' => 'draft']);

        $tool = ($this->tool)(AskingDraft::class);
        $tool->shouldRequestApproval(new Request(['title' => 'Launch notes'], 'call_1'));
        $answer = $tool->handle(new Request(['title' => 'Launch notes'], 'call_1'));

        expect($answer)->toBe('The person filled in: title, body, status. Done.')
            ->and($answer)->not->toContain('CANARY')
            ->and(AskingDraft::$handled)->toBe(['title' => 'Launch notes', 'body' => 'CANARY-BODY', 'status' => 'draft'])
            ->and(AskingDraft::$context?->approval)->toBeNull()
            ->and(Post::query()->where('body', 'CANARY-BODY')->count())->toBe(1)
            ->and(Cache::has(($this->claimKey)()))->toBeFalse()
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull();
    });

    it('hands the verified form and the values out once', function () {
        $form = ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);
        app(PendingCards::class)->answer('call_1', $form, ['body' => 'x']);
        app(PendingCards::class)->forget('call_1');

        expect(app(PendingCards::class)->answered('call_1'))->toBe(['form' => $form, 'values' => ['body' => 'x']])
            ->and(app(PendingCards::class)->answered('call_1'))->toBeNull()
            ->and(app(PendingCards::class)->answered('call_2'))->toBeNull();
    });

    it('runs the person\'s values after the resume\'s own preview gave no form, never the model\'s alone', function () {
        $this->travelTo('2026-09-20 12:00:00');
        $form = ($this->paused)(AskingRules::class, ['when' => '2026-09-19']);
        app(PendingCards::class)->answer('call_1', $form, ['when' => '2026-10-01']);

        // A rule that flipped: the model's stored date now passes, so the resume previews nothing.
        $this->travelTo('2026-09-10 12:00:00');
        $tool = ($this->tool)(AskingRules::class);

        expect($tool->shouldRequestApproval(new Request(['when' => '2026-09-19'], 'call_1')))->toBeNull()
            ->and(app(PendingCards::class)->has('call_1'))->toBeFalse();

        $answer = $tool->handle(new Request(['when' => '2026-09-19'], 'call_1'));

        expect($answer)->toBe('The person filled in: when. Done.')
            ->and(AskingRules::$handled)->toBe(['when' => '2026-10-01']);
    });

    it('runs nothing on arguments the verified form was not built from', function () {
        $form = ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);
        app(PendingCards::class)->answer('call_1', $form, ['body' => 'x', 'status' => 'draft']);

        $answer = ($this->tool)(AskingDraft::class)->handle(new Request(['title' => 'Other notes'], 'call_1'));

        expect($answer)->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(AskingDraft::$handled)->toBeNull()
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });

    it('never fills a field the person left empty from the model\'s arguments', function () {
        $form = ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);
        app(PendingCards::class)->answer('call_1', $form, ['body' => 'x', 'status' => 'draft']);

        $answer = ($this->tool)(AskingDraft::class)->handle(new Request(['title' => 'Launch notes'], 'call_1'));

        expect($answer)->toBe('The person filled in: body, status. Not done. Rejected: title (required).')
            ->and(AskingDraft::$handled)->toBeNull();
    });

    it('runs a call this request read no answer for as in 0.4: incomplete, it is refused naming the fields', function () {
        ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);

        $answer = ($this->tool)(AskingDraft::class)->handle(new Request(['title' => 'Launch notes'], 'call_1'));

        expect($answer)->toStartWith('Not done. Rejected: body')
            ->and(AskingDraft::$handled)->toBeNull()
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });

    it('never runs an answer for a tool built without an agent, since its form rides a ticket naming no conversation', function () {
        $form = ($this->paused)(AskingDraft::class, ['title' => 'Launch notes']);
        app(PendingCards::class)->answer('call_1', $form, ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);

        $answer = ($this->tool)(AskingDraft::class, pausing: false)->handle(new Request(['title' => 'Launch notes'], 'call_1'));

        expect($answer)->toBe('The person filled in: title, body, status. '.trans('agentic-actions::model.not_confirmed'))
            ->and(AskingDraft::$handled)->toBeNull();
    });
});

describe('Destructive and External tools', function () {
    it('still get 0.4\'s card for a complete call', function () {
        $post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
        $tool = ($this->tool)(AskingDelete::class);

        expect($tool->shouldRequestApproval(new Request(['post' => $post->id], 'call_1'))?->reason)
            ->toBe(trans('agentic-actions::approval.destructive'))
            ->and($tool->card('call_1', ['post' => $post->id]))->toBeInstanceOf(ApprovalCard::class)
            ->and($tool->card('call_1', ['post' => $post->id])?->summary)->toBe([['label' => 'Post', 'value' => 'Launch notes']]);
    });
});
