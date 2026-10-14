<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\PendingCards;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Contracts\GovernsModelCalls;
use AgenticActions\Effect;
use AgenticActions\Events\ActionRefused;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\ModelCallRule;
use AgenticActions\Pipeline\Door;
use AgenticActions\Pipeline\OutcomeKind;
use AgenticActions\Runner;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Workbench\App\Models\User;

/*
 * GovernsModelCalls: the app's say over what a model may do alone. A confirm rule makes a Write call wait for a card
 * (with the app's sentence on it), a refuse rule refuses with the app's sentence while the action stays listed, a run
 * rule lifts a Destructive call's card on the agent door only, and a policy that throws refuses.
 */

/** A policy that answers one rule for every call, and records what it was asked. */
final class FixedModelCallPolicy implements GovernsModelCalls
{
    /** @var list<array{string, string|null, string}> */
    public array $asked = [];

    public function __construct(public ?ModelCallRule $answer = null, public bool $throws = false) {}

    public function rule(string $action, ?Effect $effect, ActionContext $context): ?ModelCallRule
    {
        $this->asked[] = [$action, $effect?->value, $context->surface->value];

        if ($this->throws) {
            throw new RuntimeException('policy broke');
        }

        return $this->answer;
    }
}

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Approvals'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Trace::reset();
    ConfirmedDelete::$handled = null;
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
    $this->agent = (new ConfirmingAgent($this->user))->continue('conversation-1', as: $this->user);

    $this->policy = fn (?ModelCallRule $rule, bool $throws = false): FixedModelCallPolicy => tap(new FixedModelCallPolicy($rule, $throws), fn ($policy) => app()->instance(GovernsModelCalls::class, $policy));

    $this->tool = fn (string $class, bool $pausing = true): ActionTool => new ActionTool(
        ClassExposure::of($class),
        ActionContext::agent($this->user),
        ['approvals', 'default'],
        $pausing ? $this->agent : null,
    );
});

afterEach(function () {
    app()->forgetInstance(GovernsModelCalls::class);
    app()->offsetUnset(GovernsModelCalls::class);
});

it('makes a Write call wait for a card that shows the app\'s rule, and runs it only on the card\'s claim', function () {
    ($this->policy)(ModelCallRule::confirm('Your organization asks before writing notes.'));
    $arguments = ['title' => 'Hello', 'body' => 'World'];

    $approval = ($this->tool)(CreateNote::class)->shouldRequestApproval(new Request($arguments, 'call_1'));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe('This changes something in the app. Go ahead?')
        ->and(app(PendingCards::class)->has('call_1'))->toBeTrue()
        ->and($this->user->posts()->where('title', 'Hello')->exists())->toBeFalse();

    $context = ActionContext::agent($this->user)->withApproval(new ApprovalTicket('conversation-1', 'call_1'));
    $card = app(Runner::class)->preview(ClassExposure::of(CreateNote::class), $arguments, $context, ['default']);

    expect($card)->toBeInstanceOf(ApprovalCard::class)
        ->and($card->part('call_1')['data'])->toMatchArray(['effect' => 'write', 'rule' => 'Your organization asks before writing notes.']);

    // Without the claim, step 8 refuses.
    $refused = app(Runner::class)->run(ClassExposure::of(CreateNote::class), $arguments, $context, Door::Agent, ['default']);
    expect($refused->kind())->toBe(OutcomeKind::Refused)->and($this->user->posts()->where('title', 'Hello')->exists())->toBeFalse();

    expect(app(ApprovalClaims::class)->mint('conversation-1', 'call_1', $card))->toBeTrue();
    $ran = app(Runner::class)->run(ClassExposure::of(CreateNote::class), $arguments, $context, Door::Agent, ['default']);

    expect($ran->kind())->toBe(OutcomeKind::Ok)->and($this->user->posts()->where('title', 'Hello')->exists())->toBeTrue();
});

it('refuses a Write call over MCP or without an agent that can pause when the app asks for a confirmation', function () {
    ($this->policy)(ModelCallRule::confirm('Your organization asks before writing notes.'));
    Sanctum::actingAs($this->user, ['actions:read', 'actions:write', 'actions:destructive']);

    $mcp = app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi', 'body' => 'x'], ActionContext::mcp($this->user, null), Door::Mcp);
    $agent = app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi', 'body' => 'x'], ActionContext::agent($this->user), Door::Agent, ['default']);

    expect($mcp->kind())->toBe(OutcomeKind::Refused)
        ->and($mcp->forModel(false))->toContain('nobody can confirm it here')
        ->and($agent->kind())->toBe(OutcomeKind::Refused)
        ->and($agent->forModel(false))->toContain('nobody can confirm it here')
        ->and($this->user->posts()->where('title', 'Hi')->exists())->toBeFalse()
        // Still listed, so the model can say why.
        ->and(app(Runner::class)->exposed(ClassExposure::of(CreateNote::class), ActionContext::mcp($this->user, null), Door::Mcp))->toBeTrue();
});

it('refuses with the app\'s sentence, keeps the action listed and fires ActionRefused', function () {
    Event::fake([ActionRefused::class]);
    ($this->policy)(ModelCallRule::refuse('Your organization does not let the AI write notes.'));

    $outcome = app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi', 'body' => 'x'], ActionContext::agent($this->user), Door::Agent, ['default']);
    $reply = ($this->tool)(CreateNote::class, pausing: false)->handle(new Request(['title' => 'Hi', 'body' => 'x'], 'call_9'));

    expect($outcome->kind())->toBe(OutcomeKind::Refused)
        ->and($outcome->forModel(false))->toContain('Your organization does not let the AI write notes.')
        ->and($reply)->toContain('Your organization does not let the AI write notes.')
        ->and($this->user->posts()->where('title', 'Hi')->exists())->toBeFalse()
        ->and(app(Runner::class)->exposed(ClassExposure::of(CreateNote::class), ActionContext::agent($this->user), Door::Agent, ['default']))->toBeTrue();

    Event::assertDispatched(ActionRefused::class);
});

it('never consults the policy for a person on the page', function () {
    $policy = ($this->policy)(ModelCallRule::refuse('No.'));

    $outcome = app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi', 'body' => 'x'], ActionContext::http($this->user), Door::InProcess);

    expect($outcome->kind())->toBe(OutcomeKind::Ok)->and($policy->asked)->toBe([]);
});

it('lifts a Destructive call\'s card on the agent door when the app says run', function () {
    ($this->policy)(ModelCallRule::run());
    $tool = ($this->tool)(ConfirmedDelete::class, pausing: false);

    expect($tool->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_1')))->toBeNull();

    $reply = $tool->handle(new Request(['post' => $this->post->id], 'call_1'));

    expect(ConfirmedDelete::$handled)->not->toBeNull()
        ->and($reply)->not->toContain('Not done');
});

it('keeps a Destructive call off MCP and a queued run even when the app says run', function () {
    ($this->policy)(ModelCallRule::run());
    Sanctum::actingAs($this->user, ['actions:read', 'actions:write', 'actions:destructive']);

    $mcp = app(Runner::class)->run(ClassExposure::of(ConfirmedDelete::class), ['post' => $this->post->id], ActionContext::mcp($this->user, null), Door::Mcp);
    $queued = app(Runner::class)->run(ClassExposure::of(ConfirmedDelete::class), ['post' => $this->post->id], ActionContext::queued(Surface::Agent, $this->user, null, 'en', [], null, null), Door::InProcess);

    expect($mcp->kind())->toBe(OutcomeKind::NotFound)
        ->and($queued->kind())->toBe(OutcomeKind::NotFound)
        ->and(ConfirmedDelete::$handled)->toBeNull();
});

it('refuses, and reports, when the policy throws', function () {
    ($this->policy)(null, throws: true);

    $outcome = app(Runner::class)->run(ClassExposure::of(CreateNote::class), ['title' => 'Hi', 'body' => 'x'], ActionContext::agent($this->user), Door::Agent, ['default']);

    expect($outcome->kind())->toBe(OutcomeKind::Refused)->and($this->user->posts()->where('title', 'Hi')->exists())->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps the package\'s rule when the policy answers null', function () {
    ($this->policy)(null);

    expect(($this->tool)(CreateNote::class)->shouldRequestApproval(new Request(['title' => 'Hi', 'body' => 'x'], 'call_1')))->toBeNull()
        ->and(($this->tool)(ConfirmedDelete::class)->shouldRequestApproval(new Request(['post' => $this->post->id], 'call_2')))->toBeInstanceOf(Approval::class);
});
