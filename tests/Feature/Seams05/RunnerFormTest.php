<?php

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Elicitation\Form;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exceptions\ReadActionWrote;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Elicitation\AskingDelete;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingEcho;
use Tests\Fixtures\Elicitation\AskingMistake;
use Tests\Fixtures\Elicitation\AskingNested;
use Tests\Fixtures\Elicitation\AskingPassword;
use Tests\Fixtures\Elicitation\AskingSecret;
use Tests\Fixtures\Elicitation\AskingWrites;
use Tests\Fixtures\Elicitation\NotAsking;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Runner::preview() builds a form for an asking Read or Write call whose validation refused only fields a form can
 * hold, never for a Destructive or External call; step 8 runs a form's answer only on that form's
 * claim, once, and a card's rule comes first.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    AskingDraft::reset();
    AskingDelete::$handled = false;

    $this->user = User::factory()->create();
    $this->context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
});

/**
 * Preview a call of a class for the asking toolset.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $arguments
 */
function seams05Preview(string $class, array $arguments, ?ActionContext $context = null): ApprovalCard|Form|null
{
    return app(Runner::class)->preview(ClassExposure::of($class), $arguments, $context ?? test()->context, ['asking']);
}

/**
 * Run a class through the agent door with the asking toolset.
 *
 * @param  class-string<Action>  $class
 * @param  array<string, mixed>  $input
 */
function seams05Run(string $class, array $input, ActionContext $context): Outcome
{
    return app(Runner::class)->run(ClassExposure::of($class), $input, $context, Door::Agent, ['asking']);
}

describe('the form', function () {
    it('asks the fields the call left out, and the confirmed title opening on the model\'s value, running nothing', function () {
        Event::fake([ActionCompleted::class, ActionRefused::class, ActionFailed::class]);

        $form = seams05Preview(AskingDraft::class, ['title' => 'Launch notes']);

        expect($form)->toBeInstanceOf(Form::class)
            ->and($form->fields)->toBe(['title', 'body', 'status'])
            ->and($form->schema['properties']['title']['default'])->toBe('Launch notes')
            ->and($form->schema['required'])->toBe(['title', 'body', 'status'])
            ->and($form->message)->toBe('A few details for your post.')
            ->and($form->entry->name)->toBe('asking-draft')
            ->and($form->context->actor)->toBe($this->user)
            ->and($form->fingerprint)->toBe(ApprovalClaims::fingerprint(['title' => 'Launch notes']))
            ->and($form->errors)->toHaveKeys(['body', 'status'])
            ->and(AskingDraft::$handled)->toBeNull()
            ->and(Post::query()->count())->toBe(0)
            ->and(Cache::has(ApprovalClaims::PREFIX.hash('sha256', "conversation-1\ncall_1")))->toBeFalse();

        Event::assertNothingDispatched();
    });

    it('never opens a field validation refused on the model\'s value', function () {
        $form = seams05Preview(AskingDraft::class, ['title' => '']);

        expect($form->fields)->toBe(['title', 'body', 'status'])
            ->and($form->schema['properties']['title'])->not->toHaveKey('default');
    });

    it('never lets ask() see what the model\'s arguments left on the instance', function () {
        $form = seams05Preview(AskingEcho::class, ['note' => '', 'tag' => 'CANARY-TAG']);

        expect($form->message)->toBe('Seen: null')
            ->and(json_encode([$form->params(), $form->part('call_1')]))->not->toContain('CANARY');
    });

    it('prunes an argument the agent was never offered from nothing but the fingerprint', function () {
        $form = seams05Preview(AskingDraft::class, ['title' => 'Launch notes', 'CANARY-KEY' => 'CANARY-VALUE']);

        expect(json_encode($form->part('call_1')))->not->toContain('CANARY')
            ->and($form->fingerprint)->toBe(ApprovalClaims::fingerprint(['title' => 'Launch notes', 'CANARY-KEY' => 'CANARY-VALUE']));
    });

    it('asks, reporting nothing, when an argument decoded to a number that is not finite, and binds the form to it', function (float $number, string $spelled) {
        Exceptions::fake();

        $form = seams05Preview(AskingDraft::class, ['title' => 'Launch notes', 'size' => $number]);

        expect($form)->toBeInstanceOf(Form::class)
            ->and($form->fingerprint)->not->toBe(ApprovalClaims::fingerprint(['title' => 'Launch notes', 'size' => 0.0]))
            ->and($form->fingerprint)->not->toBe(ApprovalClaims::fingerprint(['title' => 'Launch notes', 'size' => $spelled]));

        Exceptions::assertNothingReported();
    })->with(['infinity' => [INF, 'INF'], 'minus infinity' => [-INF, '-INF'], 'not a number' => [NAN, 'NAN']]);
});

describe('no form', function () {
    it('gives none for a complete call, which runs at once', function () {
        expect(seams05Preview(AskingDraft::class, ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']))->toBeNull();
    });

    it('gives none for an action that does not ask', function () {
        expect(seams05Preview(NotAsking::class, []))->toBeNull()
            ->and(seams05Run(NotAsking::class, [], ActionContext::agent($this->user))->forModel())->toBe('Not done. Rejected: title (required).');
    });

    it('gives none when a refused field is one a form cannot hold, and the call is refused naming it', function () {
        expect(seams05Preview(AskingNested::class, []))->toBeNull()
            ->and(seams05Preview(AskingNested::class, ['title' => 'x']))->toBeNull()
            ->and(seams05Run(AskingNested::class, ['title' => 'x'], ActionContext::agent($this->user))->forModel())->toBe('Not done. Rejected: address (required).');
    });

    it('gives none for a secret field, and the call is refused naming it', function (string $class, string $toolset, string $field) {
        config(['agentic-actions.agents.forbidden_keys' => []]);
        $entry = ClassExposure::of($class);

        expect(app(Runner::class)->preview($entry, [], $this->context, [$toolset]))->toBeNull()
            ->and(app(Runner::class)->run($entry, [], ActionContext::agent($this->user), Door::Agent, [$toolset])->forModel())->toBe("Not done. Rejected: {$field} (required).");
    })->with([
        'a secret key' => [AskingSecret::class, 'secrets', 'password'],
        'a password rule' => [AskingPassword::class, 'asking', 'confirm_with'],
    ]);

    it('gives none when the input-free authorize() denies the person', function () {
        AskingDraft::$allowed = false;

        expect(seams05Preview(AskingDraft::class, ['title' => 'Launch notes']))->toBeNull();
    });

    it('gives none for another toolset or a guest', function () {
        expect(app(Runner::class)->preview(ClassExposure::of(AskingDraft::class), [], $this->context, ['default']))->toBeNull()
            ->and(seams05Preview(AskingDraft::class, [], ActionContext::agent(null)->withApproval(new ApprovalTicket(null, null))))->toBeNull();
    });

    it('gives none, and reports, when ask() writes, whatever reads.guard says', function (bool $guard) {
        config(['agentic-actions.reads.guard' => $guard]);
        Exceptions::fake();

        expect(seams05Preview(AskingWrites::class, []))->toBeNull()
            ->and(Post::query()->where('title', 'WRITTEN-BY-ASK')->exists())->toBeFalse();

        Exceptions::assertReported(ReadActionWrote::class);
    })->with(['reads.guard on' => [true], 'reads.guard off' => [false]]);

    it('gives none, and reports, when ask() names a field the agent is not offered', function () {
        Exceptions::fake();

        expect(seams05Preview(AskingMistake::class, []))->toBeNull();

        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === '['.AskingMistake::class.'] ask() names [subtitle], which agents are not offered.');
    });
});

describe('Destructive and External actions never ask', function () {
    beforeEach(function () {
        $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    });

    it('refuses an incomplete call naming the field, with no form and no claim', function () {
        expect(seams05Preview(AskingDelete::class, []))->toBeNull()
            ->and(seams05Run(AskingDelete::class, [], $this->context)->forModel())->toBe('Not done. Rejected: post (required).')
            ->and(Cache::has(ApprovalClaims::PREFIX.hash('sha256', "conversation-1\ncall_1")))->toBeFalse()
            ->and(AskingDelete::$handled)->toBeFalse();
    });

    it('refuses a form ticket at step 8 without the card\'s claim', function () {
        $form = seams05Preview(AskingDraft::class, ['title' => 'Launch notes']);
        app(ApprovalClaims::class)->mint('conversation-1', 'call_1', $form);

        $outcome = seams05Run(AskingDelete::class, ['post' => $this->post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1', $form)));

        expect($outcome->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(AskingDelete::$handled)->toBeFalse()
            ->and(Post::query()->whereKey($this->post->id)->exists())->toBeTrue();
    });
});

describe('step 8 for a form', function () {
    beforeEach(function () {
        $this->form = seams05Preview(AskingDraft::class, ['title' => 'Launch notes']);
        app(ApprovalClaims::class)->mint('conversation-1', 'call_1', $this->form);

        $this->answered = fn (?Form $form = null, ?ApprovalTicket $ticket = null, ?User $as = null): ActionContext => ActionContext::agent($as ?? $this->user)
            ->withApproval($ticket ?? new ApprovalTicket('conversation-1', 'call_1', $form ?? $this->form));
        $this->merged = $this->form->merge(['title' => 'Launch notes'], ['title' => 'Launch notes', 'body' => 'What shipped.', 'status' => 'draft']);
    });

    it('runs the merged input once on the form\'s claim, and handle() never sees the ticket', function () {
        $context = ($this->answered)();

        $first = seams05Run(AskingDraft::class, $this->merged, $context);
        $second = seams05Run(AskingDraft::class, $this->merged, ($this->answered)());

        expect($first->kind())->toBe(OutcomeKind::Ok)
            ->and(AskingDraft::$handled)->toBe(['title' => 'Launch notes', 'body' => 'What shipped.', 'status' => 'draft'])
            ->and(AskingDraft::$context?->approval)->toBeNull()
            ->and(AskingDraft::$current?->requestId)->toBe($context->requestId)
            ->and(AskingDraft::$current?->approval)->toBeNull()
            ->and($second->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and($second->status())->toBe(409)
            ->and(Post::query()->count())->toBe(1);
    });

    it('refuses a form for another action, a ticket naming no call, and a form built for another person', function (Closure $context) {
        $outcome = seams05Run(AskingDraft::class, $this->merged, $context->call($this));

        expect($outcome->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(AskingDraft::$handled)->toBeNull()
            ->and(app(ApprovalClaims::class)->holds('conversation-1', 'call_1', $this->form))->toBeTrue();
    })->with([
        'another action' => [fn (): ActionContext => ($this->answered)(new Form(ClassExposure::of(NotAsking::class), $this->form->context, 'x', $this->form->schema, $this->form->fields, [], [], $this->form->fingerprint))],
        'no call' => [fn (): ActionContext => ($this->answered)(ticket: new ApprovalTicket('conversation-1', null, $this->form))],
        'no conversation' => [fn (): ActionContext => ($this->answered)(ticket: new ApprovalTicket(null, 'call_1', $this->form))],
        'another person' => [fn (): ActionContext => ($this->answered)(as: User::factory()->create())],
    ]);

    it('refuses a form whose claim was never minted', function () {
        $outcome = seams05Run(AskingDraft::class, $this->merged, ($this->answered)(ticket: new ApprovalTicket('conversation-1', 'call_2', $this->form)));

        expect($outcome->forModel())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(AskingDraft::$handled)->toBeNull();
    });

    it('runs a Write with a ticket and no form as in 0.4', function () {
        $outcome = seams05Run(AskingDraft::class, $this->merged, ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_9')));

        expect($outcome->kind())->toBe(OutcomeKind::Ok)
            ->and(app(ApprovalClaims::class)->holds('conversation-1', 'call_1', $this->form))->toBeTrue();
    });

    it('tells the model the refused field and its rules, never the message, and leaves the claim', function () {
        $outcome = seams05Run(AskingDraft::class, [...$this->merged, 'body' => 'CANARY-'.str_repeat('x', 5000)], ($this->answered)());

        expect($outcome->forModel())->toBe('Not done. Rejected: body (max).')
            ->and(app(ApprovalClaims::class)->holds('conversation-1', 'call_1', $this->form))->toBeTrue();
    });
});
