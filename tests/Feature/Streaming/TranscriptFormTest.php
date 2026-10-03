<?php

use AgenticActions\Streaming\ChatRequest;
use AgenticActions\Streaming\Transcript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Elicitation\AskingAgent;
use Tests\Fixtures\Elicitation\AskingChoices;
use Tests\Fixtures\Elicitation\AskingDraft;
use Tests\Fixtures\Elicitation\AskingRules;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A reloaded chat restores the form of a call its newest turn still waits on: the input-less tool part and the
 * form, rebuilt now on the server from the call's stored arguments, while its claim holds for that form. A call that
 * no longer needs a form, or whose claim lapsed, shows none, and so does an answered one.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'app.name' => 'Blog',
        'agentic-actions.discovery.paths' => [dirname(__DIR__, 2).'/Fixtures/Elicitation'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    AskingDraft::reset();
    AskingRules::$handled = null;
    AskingChoices::$handled = null;
    Exceptions::fake();

    $this->user = User::factory()->create(['name' => 'Ada']);
    $this->team = null;

    // A turn paused on the scripted call; the conversation's id.
    $this->pause = function (array $call = ['asking-draft', ['title' => 'Launch notes', 'mood' => 'CANARY-ARG']]): string {
        (new ScriptedGateway([$call], 'Saved it.', 'A few details first.'))->install();

        return (string) (new AskingAgent($this->user, $this->team))->forUser($this->user)->prompt('Draft a post.')->conversationId;
    };

    $this->agent = fn (string $id): AskingAgent => (new AskingAgent($this->user, $this->team))->continue($id, as: $this->user);
    $this->reload = fn (string $id): array => Transcript::forUseChat($id, $this->user, agent: ($this->agent)($id));
    $this->words = fn (string $id): array => Transcript::forUseChat($id, $this->user);
});

it('restores the waiting call\'s input-less tool part and its form after the turn\'s words', function () {
    $id = ($this->pause)();

    $transcript = ($this->reload)($id);
    $last = end($transcript);

    expect(array_column($transcript, 'role'))->toBe(['user', 'assistant'])
        ->and($last['parts'][0])->toBe(['type' => 'text', 'text' => 'A few details first.'])
        ->and($last['parts'][1])->toEqual(['type' => 'tool-asking-draft', 'toolCallId' => 'call_1', 'state' => 'approval-requested', 'input' => new stdClass, 'approval' => ['id' => 'call_1']])
        ->and($last['parts'][2]['type'])->toBe('data-elicitation')
        ->and($last['parts'][2]['id'])->toBe('elicitation:call_1')
        ->and($last['parts'][2]['data']['params']['message'])->toBe('A few details for your post.')
        ->and(array_keys($last['parts'][2]['data']['params']['requestedSchema']['properties']))->toBe(['title', 'body', 'status'])
        ->and($last['parts'][2]['data']['labels']['source'])->toBe('Asked by Blog')
        ->and(json_encode($transcript, JSON_THROW_ON_ERROR))->not->toContain('CANARY-ARG');
});

it('rebuilds the form now, so a choice added since the pause shows, and its answer still counts', function () {
    $this->useTeamTenancy();
    $this->team = Team::factory()->create();
    $this->team->users()->attach($this->user->id);
    $id = ($this->pause)(['asking-choices', []]);

    $grace = User::factory()->create(['name' => 'Grace']);
    $this->team->users()->attach($grace->id);

    $transcript = ($this->reload)($id);
    $owner = end($transcript)['parts'][2]['data']['params']['requestedSchema']['properties']['owner'];

    expect(array_column($owner['oneOf'], 'title'))->toBe(['Ada', 'Grace']);

    // Answering the restored form, on the stored message's id.
    $part = ['type' => 'tool-asking-choices', 'toolCallId' => 'call_1', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1', 'approved' => true], 'elicitation' => ['action' => 'accept', 'content' => ['owner' => (string) $grace->id]]];
    $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['messages' => [['id' => end($transcript)['id'], 'role' => 'assistant', 'parts' => [$part]]]], JSON_THROW_ON_ERROR));
    $request->setUserResolver(fn (): User => $this->user);
    $agent = ($this->agent)($id);

    $agent->prompt(ChatRequest::from($request, $agent));

    expect(AskingChoices::$handled)->toBe(['owner' => $grace->id]);
});

it('shows no form once the claim lapsed', function () {
    $id = ($this->pause)();

    $this->travel(config('agentic-actions.approvals.ttl') + 1)->seconds();

    expect(($this->reload)($id))->toBe(($this->words)($id));
});

it('shows no form once the call\'s stored arguments pass on their own', function () {
    $this->travelTo('2026-09-20 12:00:00');
    $id = ($this->pause)(['asking-rules', ['when' => '2026-09-19']]);

    expect(json_encode(($this->reload)($id), JSON_THROW_ON_ERROR))->toContain('data-elicitation');

    $this->travelTo('2026-09-10 12:00:00');

    expect(($this->reload)($id))->toBe(($this->words)($id));
});
