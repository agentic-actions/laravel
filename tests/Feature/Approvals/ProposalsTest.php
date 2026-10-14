<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Contracts\GovernsModelCalls;
use AgenticActions\Effect;
use AgenticActions\ModelCallRule;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ProposingAgent;
use Workbench\App\Models\User;

/*
 * ProposesCalls: an agent that cannot pause is offered the calls that wait for a confirmation and proposes each one
 * that would get a card, running nothing. The app's refuse rule still refuses, its run rule still runs, and a call that
 * fails a step before the card is refused, never proposed.
 */

/** A policy that answers one rule per action name, and null for the rest. */
final class ProposalPolicy implements GovernsModelCalls
{
    /**
     * @param  array<string, ModelCallRule>  $rules
     */
    public function __construct(public array $rules) {}

    public function rule(string $action, ?Effect $effect, ActionContext $context): ?ModelCallRule
    {
        return $this->rules[$action] ?? null;
    }
}

beforeEach(function () {
    $this->skipUnlessAi();

    config(['agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Approvals']]);

    $this->refreshActions();
    Trace::reset();
    ConfirmedDelete::$handled = null;
    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);

    $this->rules = fn (array $rules) => app()->instance(GovernsModelCalls::class, new ProposalPolicy($rules));

    $this->tool = function (ProposingAgent $agent, string $name): ActionTool {
        foreach ($agent->tools() as $tool) {
            if ($tool instanceof ActionTool && $tool->name() === $name) {
                return $tool;
            }
        }

        throw new RuntimeException("{$name} is not offered");
    };
});

afterEach(function () {
    app()->forgetInstance(GovernsModelCalls::class);
    app()->offsetUnset(GovernsModelCalls::class);
});

it('offers a Destructive call to an agent that proposes, and proposes it with what its card would show, running nothing', function () {
    $agent = new ProposingAgent($this->user);
    $tool = ($this->tool)($agent, 'confirmed-delete');
    $request = new Request(['post' => $this->post->id], 'call_1');

    // It never pauses: the agent has no conversation to pause in.
    expect($tool->shouldRequestApproval($request))->toBeNull();

    $reply = $tool->handle($request);

    expect($reply)->toBe(trans('agentic-actions::model.proposed'))
        ->and(ConfirmedDelete::$handled)->toBeNull()
        ->and($this->post->fresh())->not->toBeNull()
        ->and($agent->proposals)->toHaveCount(1);

    $proposal = $agent->proposals[0];

    expect($proposal->action)->toBe('confirmed-delete')
        ->and($proposal->effect)->toBe(Effect::Destructive)
        ->and($proposal->arguments)->toBe(['post' => $this->post->id])
        ->and($proposal->title)->toBe('Delete this post? This cannot be undone.')
        ->and($proposal->summary[0])->toBe(['label' => 'Post', 'value' => 'Launch notes'])
        ->and($proposal->rule)->toBeNull()
        ->and($proposal->toolCallId)->toBe('call_1')
        ->and($proposal->context->actor)->toBe($this->user)
        ->and($proposal->context->approval)->toBeNull();
});

it('proposes a Write call the app\'s rule confirms, with the rule, and writes nothing', function () {
    ($this->rules)(['create-note' => ModelCallRule::confirm('Your team asks before writing notes.')]);
    $agent = new ProposingAgent($this->user);

    $reply = ($this->tool)($agent, 'create-note')->handle(new Request(['title' => 'Hello', 'body' => 'World'], 'call_2'));

    expect($reply)->toBe(trans('agentic-actions::model.proposed'))
        ->and($this->user->posts()->where('title', 'Hello')->exists())->toBeFalse()
        ->and($agent->proposals)->toHaveCount(1)
        ->and($agent->proposals[0]->rule)->toBe('Your team asks before writing notes.')
        ->and($agent->proposals[0]->title)->toBe('This changes something in the app. Go ahead?');
});

it('refuses with the app\'s sentence, and proposes nothing, when the app\'s rule refuses', function () {
    ($this->rules)(['confirmed-delete' => ModelCallRule::refuse('Your team does not let the AI delete posts.')]);
    $agent = new ProposingAgent($this->user);

    $reply = ($this->tool)($agent, 'confirmed-delete')->handle(new Request(['post' => $this->post->id], 'call_3'));

    expect($reply)->toContain('Your team does not let the AI delete posts.')
        ->and($agent->proposals)->toBe([])
        ->and(ConfirmedDelete::$handled)->toBeNull();
});

it('runs, and proposes nothing, when the app\'s rule runs the call', function () {
    ($this->rules)(['confirmed-delete' => ModelCallRule::run()]);
    $agent = new ProposingAgent($this->user);

    $reply = ($this->tool)($agent, 'confirmed-delete')->handle(new Request(['post' => $this->post->id], 'call_4'));

    expect($reply)->not->toBe(trans('agentic-actions::model.proposed'))
        ->and(ConfirmedDelete::$handled)->not->toBeNull()
        ->and($this->post->fresh())->toBeNull()
        ->and($agent->proposals)->toBe([]);
});

it('runs a Write call with no rule, as for any agent', function () {
    $agent = new ProposingAgent($this->user);

    ($this->tool)($agent, 'create-note')->handle(new Request(['title' => 'Hello', 'body' => 'World'], 'call_5'));

    expect($this->user->posts()->where('title', 'Hello')->exists())->toBeTrue()->and($agent->proposals)->toBe([]);
});

it('refuses, and proposes nothing, a call its card\'s authorize() denies', function () {
    $other = User::factory()->create();
    $theirs = $other->posts()->create(['title' => 'Not yours', 'body' => 'x', 'status' => 'draft']);
    $agent = new ProposingAgent($this->user);

    $reply = ($this->tool)($agent, 'confirmed-delete')->handle(new Request(['post' => $theirs->id], 'call_6'));

    expect($reply)->toBe(trans('agentic-actions::model.denied'))
        ->and($agent->proposals)->toBe([])
        ->and($theirs->fresh())->not->toBeNull();
});

it('answers with the sentence propose() returns', function () {
    $agent = new ProposingAgent($this->user, sentence: 'Saved for the editor to review.');

    expect(($this->tool)($agent, 'confirmed-delete')->handle(new Request(['post' => $this->post->id], 'call_7')))
        ->toBe('Saved for the editor to review.');
});

it('reports a propose() that throws, and runs nothing', function () {
    $agent = new ProposingAgent($this->user, throws: true);

    $reply = ($this->tool)($agent, 'confirmed-delete')->handle(new Request(['post' => $this->post->id], 'call_8'));

    expect($reply)->toBe(trans('agentic-actions::model.failed'))
        ->and(ConfirmedDelete::$handled)->toBeNull()
        ->and($this->post->fresh())->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
