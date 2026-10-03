<?php

use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionRefused;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Forms on the workbench, through its routes: TeamAssistant drafts a team post,
 * the call leaves the body and the status out, the turn pauses on a form, and the member answers it from their own
 * session. The model is scripted on the provider, so every resume runs laravel/ai's own resume path. Each request
 * forgets the scoped instances first, as a new PHP-FPM request would.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);

    $this->completed = [];
    $this->refused = [];
    Event::listen(ActionCompleted::class, function (ActionCompleted $event): void {
        $this->completed[] = $event->action;
    });
    Event::listen(ActionRefused::class, function (ActionRefused $event): void {
        $this->refused[] = [$event->action, $event->status];
    });

    // Every claim key written, the claim itself or its marker.
    $this->claimWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event): void {
        if (str_starts_with($event->key, ApprovalClaims::PREFIX)) {
            $this->claimWrites[] = $event->key;
        }
    });

    $this->script = fn (array $calls = [['draft-team-post', ['title' => 'Launch notes']]]): ScriptedGateway => $this->gateway = (new ScriptedGateway($calls, 'Saved as you asked.', 'I can draft it once you add a few details.'))->install();
    ($this->script)();

    // One request to the team's assistant, as the member's own session. $newWorker false keeps the scoped instances.
    $this->send = function (array $message, array $headers = [], bool $newWorker = true): TestResponse {
        if ($newWorker) {
            app()->forgetScopedInstances();
        }

        return $this->actingAs($this->user)->json('POST', '/teams/acme/assistant', ['messages' => [$message]], ['Accept' => 'application/json, text/event-stream', ...$headers]);
    };

    // The member asks; the turn pauses on the form. The parts of the stream.
    $this->ask = fn (): array => Parts::of(($this->send)(['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Draft a team post called Launch notes.']]])
        ->assertOk()
        ->streamedContent());

    // The member's answer to call_1, as the /ai-sdk transport posts it, continuing the paused message; a check
    // with $precognition.
    $this->answer = fn (array $result, string $messageId = 'msg-a1', bool $precognition = false): TestResponse => ($this->send)([
        'id' => $messageId,
        'role' => 'assistant',
        'parts' => [[
            'type' => 'tool-draft-team-post',
            'toolCallId' => 'call_1',
            'state' => 'approval-responded',
            'approval' => ['id' => 'call_1', 'approved' => $result['action'] === 'accept'],
            'elicitation' => $result,
        ]],
    ], $precognition ? ['Precognition' => 'true'] : []);

    $this->accept = fn (array $content, string $messageId = 'msg-a1', bool $precognition = false): TestResponse => ($this->answer)(['action' => 'accept', 'content' => $content], $messageId, $precognition);

    $this->valid = ['title' => 'Launch notes', 'body' => 'What shipped this week.', 'status' => 'draft'];

    $this->conversation = fn (): string => (string) DB::table('agent_conversations')->value('id');
    $this->claimKey = fn (): string => ApprovalClaims::PREFIX.hash('sha256', ($this->conversation)()."\ncall_1");

    // The stored call_1 of the latest assistant message: the model's arguments and the result the model read.
    $this->stored = function (): ?array {
        foreach (json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->latest('id')->value('steps'), true) as $step) {
            foreach ($step['tool_calls'] as $call) {
                if ($call['id'] === 'call_1') {
                    return $call;
                }
            }
        }

        return null;
    };

    $this->modelRead = fn (): ?string => (($this->stored)() ?? [])['result'] ?? null;

    // The form the pause streamed.
    $this->form = [
        'type' => 'data-elicitation',
        'id' => 'elicitation:call_1',
        'data' => [
            'action' => 'draft-team-post',
            'params' => [
                'mode' => 'form',
                'message' => 'A few details for your post.',
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'title' => 'Title', 'minLength' => 1, 'maxLength' => 120, 'default' => 'Launch notes'],
                        'body' => ['type' => 'string', 'title' => 'Body', 'maxLength' => 5000, 'x-agentic-actions' => ['widget' => 'textarea']],
                        'status' => ['type' => 'string', 'title' => 'Status', 'oneOf' => [['const' => 'draft', 'title' => 'Draft'], ['const' => 'published', 'title' => 'Published']]],
                    ],
                    'required' => ['title', 'body', 'status'],
                ],
            ],
            'labels' => ['source' => 'Asked by Laravel', 'submit' => 'Submit', 'decline' => 'Decline', 'cancel' => 'Not now'],
        ],
    ];
});

describe('the copilot asks, and runs only what the member submits', function () {
    it('pauses with nothing run, one claim, and streams the form after the input-less tool part', function () {
        $parts = ($this->ask)();

        expect(Post::query()->count())->toBe(0)
            ->and($this->completed)->toBe([])
            ->and(collect($parts)->firstWhere('type', 'tool-input-available'))->toBe(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => 'draft-team-post', 'input' => []])
            ->and(collect($parts)->firstWhere('type', 'tool-approval-request'))->toBe(['type' => 'tool-approval-request', 'toolCallId' => 'call_1', 'approvalId' => 'call_1'])
            ->and(collect($parts)->firstWhere('type', 'data-elicitation'))->toBe($this->form)
            ->and(Parts::types($parts))->not->toContain('data-approval')
            ->and($this->claimWrites)->toBe([($this->claimKey)()])
            ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('status'))->toBe('paused');

        Exceptions::assertNothingReported();
    });

    it('checks the answer with Precognition, then drafts the post once with the member\'s values, and the model reads the field names', function () {
        ($this->ask)();

        ($this->accept)($this->valid, precognition: true)
            ->assertNoContent()
            ->assertHeader('Precognition', 'true')
            ->assertHeader('Precognition-Success', 'true');

        expect(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();

        $parts = Parts::of(($this->accept)($this->valid)->assertOk()->streamedContent());
        $post = Post::query()->sole();

        expect($post->only(['title', 'body', 'status', 'user_id', 'team_id']))->toBe([...$this->valid, 'user_id' => $this->user->id, 'team_id' => $this->team->id])
            ->and($this->completed)->toBe(['draft-team-post'])
            ->and($parts[0])->toBe(['type' => 'start', 'messageId' => 'msg-a1'])
            ->and(Parts::rows($parts))->toHaveCount(1)
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'draft-team-post', 'status' => 'done', 'effect' => 'write', 'touches' => ['posts']])
            ->and(collect($parts)->firstWhere('type', 'tool-output-available'))->toBe(['type' => 'tool-output-available', 'toolCallId' => 'call_1', 'output' => null])
            ->and(($this->modelRead)())->toBe('The person filled in: title, body, status. '.trans('agentic-actions::model.done'))
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();

        Exceptions::assertNothingReported();
    });

    it('drafts nothing on Decline or Not now: the row reads declined and the model reads which', function (string $action, string $line) {
        ($this->ask)();

        $parts = Parts::of(($this->answer)(['action' => $action])->assertOk()->streamedContent());

        expect(Post::query()->count())->toBe(0)
            ->and($this->completed)->toBe([])
            ->and(collect($parts)->firstWhere('type', 'tool-output-denied'))->toBe(['type' => 'tool-output-denied', 'toolCallId' => 'call_1'])
            ->and(Parts::rows($parts))->toBe([['action' => 'draft-team-post', 'label' => 'Declined', 'status' => 'declined']])
            ->and(($this->modelRead)())->toBe($line)
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();

        // Nothing waits any more, so a later Submit is stale.
        ($this->accept)($this->valid)->assertStatus(409);

        expect(Post::query()->count())->toBe(0);
    })->with([
        'Decline' => ['decline', 'The person declined to fill in title, body, status, so nothing ran. Do not ask again unless they ask.'],
        'Not now' => ['cancel', 'The person closed the form without answering, so nothing ran. Offer it again only if it still matters.'],
    ]);
});

describe('the member\'s values never reach the model', function () {
    it('keeps a canary the member typed out of everything the model was sent, the stored turn and the tool result', function () {
        ($this->ask)();

        ($this->accept)([...$this->valid, 'body' => 'CANARY-BODY-7f3 What shipped.'])->assertOk()->streamedContent();

        $stored = ($this->stored)();

        expect(Post::query()->sole()->body)->toBe('CANARY-BODY-7f3 What shipped.')
            ->and($this->gateway->steps())->toBe(2)
            ->and(json_encode($this->gateway->sent, JSON_THROW_ON_ERROR))->not->toContain('CANARY')
            ->and(DB::table('agent_conversation_messages')->get()->toJson())->not->toContain('CANARY')
            ->and(DB::table('agent_conversations')->get()->toJson())->not->toContain('CANARY')
            ->and($stored['arguments'])->toBe(['title' => 'Launch notes'])
            ->and($stored['result'])->toBe('The person filled in: title, body, status. '.trans('agentic-actions::model.done'));
    });

    it('tells the model only the refused field when the run refuses the member\'s value, though the action sends its messages', function () {
        ($this->ask)();

        // The check passes, then a teammate's post takes the member's title before the resume runs: handle() refuses it
        // with a message quoting the title, which reaches the member's page on the web and never the model.
        $answer = ($this->accept)([...$this->valid, 'title' => 'CANARY-TITLE-7f3', 'body' => 'CANARY-BODY-7f3'])->assertOk();
        Post::factory()->forTeam($this->team)->create(['title' => 'CANARY-TITLE-7f3']);

        $parts = Parts::of($answer->streamedContent());

        expect(Post::query()->count())->toBe(1)
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([['draft-team-post', 422]])
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'draft-team-post', 'status' => 'refused'])
            ->and(($this->modelRead)())->toBe('The person filled in: title, body, status. Not done. Rejected: title.')
            ->and(json_encode($this->gateway->sent, JSON_THROW_ON_ERROR))->not->toContain('CANARY')
            ->and(DB::table('agent_conversation_messages')->get()->toJson())->not->toContain('CANARY')
            ->and(json_encode($parts, JSON_THROW_ON_ERROR))->not->toContain('CANARY');
    });
});

describe('an answer is checked before any agent work', function () {
    it('answers a body over 5,000 characters with 422 and the error under body, spends nothing, and a corrected answer then runs', function (bool $precognition) {
        ($this->ask)();
        $steps = $this->gateway->steps();

        ($this->accept)([...$this->valid, 'body' => str_repeat('b', 5001)], precognition: $precognition)
            ->assertStatus(422)
            ->assertExactJson(['message' => trans('agentic-actions::ask.invalid'), 'errors' => ['body' => ['The body field must not be greater than 5000 characters.']]]);

        expect($this->gateway->steps())->toBe($steps)
            ->and(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue()
            ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('status'))->toBe('paused');

        ($this->accept)($this->valid)->assertOk()->streamedContent();

        expect(Post::query()->sole()->body)->toBe($this->valid['body'])
            ->and($this->completed)->toBe(['draft-team-post']);
    })->with(['the check' => true, 'the answer' => false]);
});

describe('a reload restores the waiting form', function () {
    it('returns the paused turn with the form rebuilt now, and answering it drafts the post', function () {
        ($this->ask)();

        app()->forgetScopedInstances();
        $response = $this->actingAs($this->user)->getJson('/teams/acme/assistant')->assertOk();
        $paused = $response->json('messages.1');

        expect($response->json('messages'))->toHaveCount(2)
            ->and($paused['parts'])->toBe([
                ['type' => 'text', 'text' => 'I can draft it once you add a few details.'],
                ['type' => 'tool-draft-team-post', 'toolCallId' => 'call_1', 'state' => 'approval-requested', 'input' => [], 'approval' => ['id' => 'call_1']],
                $this->form,
            ]);

        $parts = Parts::of(($this->accept)($this->valid, $paused['id'])->assertOk()->streamedContent());

        expect($parts[0])->toBe(['type' => 'start', 'messageId' => $paused['id']])
            ->and(Post::query()->sole()->title)->toBe('Launch notes');

        app()->forgetScopedInstances();
        expect(collect($this->getJson('/teams/acme/assistant')->json('messages'))->pluck('parts')->flatten(1)->pluck('type')->all())
            ->not->toContain('data-elicitation');
    });

    it('still shows the form after a post is added to the team, and its answer still runs', function () {
        ($this->ask)();
        Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'Roadmap']);

        app()->forgetScopedInstances();
        $response = $this->actingAs($this->user)->getJson('/teams/acme/assistant')->assertOk();

        expect($response->json('messages.1.parts.2'))->toBe($this->form);

        ($this->accept)($this->valid, (string) $response->json('messages.1.id'))->assertOk()->streamedContent();

        expect(Post::query()->where('title', 'Launch notes')->count())->toBe(1)
            ->and($this->completed)->toBe(['draft-team-post']);
    });
});

describe('a Destructive action never asks', function () {
    it('refuses delete-post {} naming post, with no form and no claim', function () {
        ($this->script)([['delete-post', []]]);

        $parts = ($this->ask)();

        expect(Parts::types($parts))->not->toContain('data-elicitation')
            ->and(Parts::types($parts))->not->toContain('data-approval')
            ->and(Parts::types($parts))->not->toContain('tool-approval-request')
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'delete-post', 'status' => 'refused'])
            ->and(($this->modelRead)())->toBe('Not done. Rejected: post (required).')
            ->and($this->claimWrites)->toBe([]);
    });

    it('still shows the confirmation card for a complete delete-post call', function () {
        $post = Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'Launch notes', 'status' => 'draft']);
        ($this->script)([['delete-post', ['post' => $post->id]]]);

        $parts = ($this->ask)();

        expect(Parts::types($parts))->toContain('data-approval')
            ->and(Parts::types($parts))->not->toContain('data-elicitation')
            ->and(collect($parts)->firstWhere('type', 'data-approval')['data']['summary'])->toBe([['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']])
            ->and($this->claimWrites)->toBe([($this->claimKey)()]);
    });
});
