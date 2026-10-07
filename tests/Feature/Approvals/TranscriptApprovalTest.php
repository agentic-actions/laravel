<?php

use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\Transcript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Ai\ToolSearchAgent;
use Tests\Fixtures\Approvals\ConfirmingAgent;
use Tests\Fixtures\Approvals\HostConfirmedTool;
use Tests\Fixtures\Approvals\HostToolAgent;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Approvals\StatelessAgent;
use Tests\Fixtures\Deferred\DeferringAgent;
use Workbench\App\Models\User;

/*
 * A reloaded chat restores the card of a call its newest turn still waits on: an input-less tool part and the card,
 * rebuilt now on the server from the current record. The call's stored arguments never leave the server.
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

    // A turn of the confirming agent, or another agent class, paused on deleting the post; the model's arguments carry a
    // canary the schema does not advertise.
    $this->pause = function (string $lead = 'I can delete it once you confirm.', string $agent = ConfirmingAgent::class): string {
        (new ScriptedGateway([['confirmed-delete', ['post' => $this->post->id, 'note' => 'CANARY-ARG']]], 'Deleted it.', $lead))->install();

        return (new $agent($this->user))->forUser($this->user)->prompt('Delete my post.')->conversationId;
    };

    $this->agent = fn (string $conversation, ?User $as = null, string $agent = ConfirmingAgent::class): Agent => (new $agent($as ?? $this->user))
        ->continue($conversation, as: $as ?? $this->user);

    $this->waitingParts = fn (): array => [
        ['type' => 'tool-confirmed-delete', 'toolCallId' => 'call_1', 'state' => 'approval-requested', 'input' => new stdClass, 'approval' => ['id' => 'call_1']],
        ['type' => 'data-approval', 'id' => 'approval:call_1', 'data' => [
            'action' => 'confirmed-delete',
            'effect' => 'destructive',
            'label' => 'Waiting for your confirmation',
            'title' => 'Delete this post? This cannot be undone.',
            'summary' => [['label' => 'Post', 'value' => $this->post->fresh()->title], ['label' => 'Status', 'value' => 'draft']],
            'confirm' => 'Confirm',
            'decline' => 'Decline',
        ]],
    ];
});

it('keeps a paused turn with no words, with the waiting parts only', function () {
    $conversation = ($this->pause)('');

    $transcript = Transcript::forUseChat($conversation, $this->user, agent: ($this->agent)($conversation));

    expect(end($transcript)['role'])->toBe('assistant')
        ->and(end($transcript)['parts'])->toEqual(($this->waitingParts)());
});

it('restores the card of an action tool inside a tool-search group, and confirming it resumes the turn', function (string $class) {
    $conversation = ($this->pause)('', $class);

    $transcript = Transcript::forUseChat($conversation, $this->user, agent: ($this->agent)($conversation, agent: $class));

    expect(end($transcript)['parts'])->toEqual(($this->waitingParts)());

    // Confirming the restored card, on the stored message's id.
    $part = ['type' => 'tool-confirmed-delete', 'toolCallId' => 'call_1', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1', 'approved' => true]];
    $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['messages' => [['id' => end($transcript)['id'], 'role' => 'assistant', 'parts' => [$part]]]], JSON_THROW_ON_ERROR));
    $request->setUserResolver(fn (): User => $this->user);
    $agent = ($this->agent)($conversation, agent: $class);

    $agent->prompt(ChatRequest::from($request, $agent));

    expect(Trace::$calls)->toContain('ConfirmedDelete::handle')
        ->and($this->post->fresh())->toBeNull();
})->with([
    'a group its tools() builds' => ToolSearchAgent::class,
    'a toolset it defers' => DeferringAgent::class,
]);

it('gives 0.2\'s words without the agent', function () {
    $conversation = ($this->pause)();

    expect(array_map(fn (array $message): array => [$message['role'], $message['parts']], Transcript::forUseChat($conversation, $this->user)))->toBe([
        ['user', [['type' => 'text', 'text' => 'Delete my post.']]],
        ['assistant', [['type' => 'text', 'text' => 'I can delete it once you confirm.']]],
    ]);
});

it('gives another person nothing, whatever agent is passed', function () {
    $conversation = ($this->pause)();
    $someoneElse = User::factory()->create();

    expect(Transcript::forUseChat($conversation, $someoneElse, agent: ($this->agent)($conversation)))->toBe([])
        ->and(Transcript::forUseChat($conversation, $someoneElse, agent: ($this->agent)($conversation, $someoneElse)))->toBe([]);
});

it('adds no waiting parts for an agent continued in another conversation, or one that cannot pause', function () {
    $conversation = ($this->pause)();
    $words = Transcript::forUseChat($conversation, $this->user);

    expect(Transcript::forUseChat($conversation, $this->user, agent: ($this->agent)('another-conversation')))->toBe($words)
        ->and(Transcript::forUseChat($conversation, $this->user, agent: (new ConfirmingAgent($this->user))->forUser($this->user)))->toBe($words)
        ->and(Transcript::forUseChat($conversation, $this->user, agent: new StatelessAgent($this->user)))->toBe($words);
});

it('adds no waiting parts for a call the pipeline would refuse now', function () {
    $conversation = ($this->pause)();
    $this->post->delete();

    expect(Transcript::forUseChat($conversation, $this->user, agent: ($this->agent)($conversation)))
        ->toBe(Transcript::forUseChat($conversation, $this->user));
});

it('adds no waiting parts for a call of a tool that is not one of the package\'s actions', function () {
    (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'CANARY-ARG']]], lead: 'I will ask first.'))->install();
    $conversation = (new HostToolAgent($this->user))->forUser($this->user)->prompt('Send a note.')->conversationId;

    $json = json_encode(Transcript::forUseChat($conversation, $this->user, agent: (new HostToolAgent($this->user))->continue($conversation, as: $this->user)), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain(HostConfirmedTool::NAME)
        ->not->toContain('CANARY')
        ->toContain('I will ask first.');
});

it('restores nothing once the call is answered', function () {
    $conversation = ($this->pause)();
    (new ConfirmingAgent($this->user))->continue($conversation, as: $this->user)->prompt(Decisions::from(['call_1' => Decision::approve()]));

    $transcript = Transcript::forUseChat($conversation, $this->user, agent: ($this->agent)($conversation));

    expect($transcript)->toBe(Transcript::forUseChat($conversation, $this->user))
        ->and(end($transcript)['parts'])->toBe([['type' => 'text', 'text' => 'Deleted it.']]);
});
