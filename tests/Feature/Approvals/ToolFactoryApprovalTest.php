<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\ToolFactory;
use AgenticActions\Facades\Actions;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\ContractOnlyAgent;
use Tests\Fixtures\Approvals\ForgetfulStore;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Approvals\StatelessAgent;
use Tests\Fixtures\Approvals\TraitOnlyAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Which agents are offered Destructive and External actions: only one that can pause for a person, as
 * ConfirmingAgents says, and never without an agent. Read and Write tools are the same for every agent.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Approvals'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Trace::reset();

    $this->user = User::factory()->create();
    $this->post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);

    // The names of the tools built for this agent, gated and not.
    $this->names = fn (iterable $tools): array => array_map(fn (ActionTool $tool): string => $tool->name(), [...$tools]);
    $this->gated = fn (iterable $tools): array => array_values(array_map(
        fn (ActionTool $tool): string => $tool->name(),
        array_filter([...$tools], fn (ActionTool $tool): bool => $tool->entry()->effect?->isModelSafe() === false),
    ));
});

it('offers an agent that stores its conversations the Destructive and External actions of its toolsets', function () {
    $tools = (new ConfirmingAgent($this->user))->forUser($this->user)->tools();

    expect(($this->gated)($tools))->toContain('confirmed-delete', 'confirmed-send');
});

it('never offers them to an agent that cannot pause for a person', function (Closure $agent) {
    $agent = $agent->call($this);

    expect(($this->gated)($agent->tools()))->toBe([])
        ->and(($this->gated)(app(ToolFactory::class)->make(ActionContext::agent($this->user), ['approvals'], $agent)))->toBe([]);
})->with([
    'an agent that does not store its conversations' => [fn (): Agent => new StatelessAgent($this->user)],
    'the trait without the Conversational contract' => [fn (): Agent => (new TraitOnlyAgent($this->user))->forUser($this->user)],
    'the contract without the trait' => [fn (): Agent => (new ContractOnlyAgent($this->user))->forUser($this->user)],
    'an agent with no participant yet' => [fn (): Agent => new ConfirmingAgent($this->user)],
    'a store that cannot read pending calls or ownership' => [function (): Agent {
        app()->instance(ConversationStore::class, new ForgetfulStore);

        return (new ConfirmingAgent($this->user))->forUser($this->user);
    }],
    'no conversation store bound' => [function (): Agent {
        app()->offsetUnset(ConversationStore::class);

        return (new ConfirmingAgent($this->user))->forUser($this->user);
    }],
]);

it('never offers them without an agent', function () {
    expect(($this->gated)(Actions::tools(ActionContext::agent($this->user), ['approvals'])))->toBe([])
        ->and(($this->gated)(Actions::tools(ActionContext::agent($this->user), ['approvals'], null)))->toBe([]);
});

it('builds the same Read and Write tools whatever the agent', function () {
    $context = ActionContext::agent($this->user);
    $safe = fn (?Agent $agent): array => array_values(array_diff(
        ($this->names)(app(ToolFactory::class)->make($context, ['default', 'approvals'], $agent)),
        ($this->gated)(app(ToolFactory::class)->make($context, ['default', 'approvals'], $agent)),
    ));

    $plain = $safe(null);

    expect($plain)->toContain('create-note', 'list-notes')
        ->and($safe((new ConfirmingAgent($this->user))->forUser($this->user)))->toBe($plain)
        ->and($safe(new StatelessAgent($this->user)))->toBe($plain)
        ->and($safe(new ConfirmingAgent($this->user)))->toBe($plain);
});

it('hands only a gated tool the agent, so only it can pause', function () {
    $tools = app(ToolFactory::class)->make(ActionContext::agent($this->user), ['default', 'approvals'], (new ConfirmingAgent($this->user))->forUser($this->user));
    $asks = [];

    foreach ($tools as $tool) {
        $asks[$tool->name()] = $tool->shouldRequestApproval(new Request(['post' => $this->post->id, 'title' => 'x', 'to' => 'ada@example.com'], 'call_1')) instanceof Approval;
    }

    expect($asks['confirmed-delete'])->toBeTrue()
        ->and($asks['confirmed-send'])->toBeTrue()
        ->and($asks['create-note'])->toBeFalse()
        ->and($asks['list-notes'])->toBeFalse();
});

it('runs nothing when a model calls a gated action by name on an agent that was not offered it', function () {
    (new ScriptedGateway([['confirmed-delete', ['post' => $this->post->id]]]))->install();

    rescue(fn () => (new StatelessAgent($this->user))->prompt('Delete my post.'), report: false);

    expect(Post::query()->whereKey($this->post->id)->exists())->toBeTrue()
        ->and(Trace::$calls)->toBe([]);
});
