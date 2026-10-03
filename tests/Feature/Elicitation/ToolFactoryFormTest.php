<?php

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\ConfirmingAgents;
use AgenticActions\Ai\ToolFactory;
use AgenticActions\Approvals\ApprovalClaims;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Approvals\ForgetfulStore;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\ForgetfulAskingAgent;
use Workbench\App\Models\User;

/*
 * Which asking tools can pause: only those built for an agent that stores its conversations, as
 * ConfirmingAgents says. Any other agent's incomplete call is refused naming the fields, with no form and no claim.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();

    $this->user = User::factory()->create();

    $this->claimWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event): void {
        if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
            $this->claimWrites[] = $event->key;
        }
    });
});

it('builds an asking tool with the agent for an agent that stores its conversations', function () {
    $tool = ConfirmingAgents::tools((new AskingAgent($this->user))->forUser($this->user))['asking-draft'];

    expect($tool->shouldRequestApproval(new Request(['title' => 'Launch notes'], 'call_1')))->toBeInstanceOf(Approval::class)
        ->and($tool->description())->toEndWith(trans('agentic-actions::model.asks'));
});

it('builds it without the agent for any other agent, so an incomplete call is refused naming the fields', function (Closure $agent) {
    $agent = $agent->call($this);
    (new ScriptedGateway([['asking-draft', ['title' => 'Launch notes']]], 'I could not.'))->install();

    $tool = ConfirmingAgents::tools($agent)['asking-draft'];
    $response = $agent->prompt('Draft a post called Launch notes.');

    expect($tool->shouldRequestApproval(new Request(['title' => 'Launch notes'], 'call_1')))->toBeNull()
        ->and($tool->description())->toBe('Draft a post for the signed-in author.')
        ->and($response->toolResults->first()?->result)->toBe('Not done. Rejected: body (required), status (required).')
        ->and($this->claimWrites)->toBe([])
        ->and(AskingDraft::$handled)->toBeNull();
})->with([
    'an agent that does not store its conversations' => [fn (): Agent => new ForgetfulAskingAgent($this->user)],
    'a store that cannot read pending calls or ownership' => [function (): Agent {
        app()->instance(ConversationStore::class, new ForgetfulStore);

        return (new AskingAgent($this->user))->forUser($this->user);
    }],
]);

it('never builds it with an agent when there is none', function () {
    $tools = app(ToolFactory::class)->make(ActionContext::agent($this->user), ['asking']);
    $draft = collect($tools)->first(fn (ActionTool $tool): bool => $tool->name() === 'asking-draft');

    expect($draft->shouldRequestApproval(new Request(['title' => 'x'], 'call_1')))->toBeNull();
});
