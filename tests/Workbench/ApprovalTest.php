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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Confirmations on the workbench, through its routes: TeamAssistant asks to delete a
 * post, the turn pauses on a card, and the member answers from their own session. The model is scripted on the
 * provider, so every resume runs laravel/ai's own resume path. Each request forgets the scoped instances first, as a
 * new PHP-FPM request would.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);
    $this->post = Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'Launch notes', 'status' => 'draft']);

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

    $this->gateway = (new ScriptedGateway([['delete-post', ['post' => $this->post->id]]], 'Done as you asked.', 'I will ask you first.'))->install();

    // One request to the team's assistant, as the member's own session. $newWorker false keeps the scoped instances,
    // for a request sent while an earlier one's stream, which still reads them, has not run yet.
    $this->send = function (array $message, string $team = 'acme', bool $newWorker = true): TestResponse {
        if ($newWorker) {
            app()->forgetScopedInstances();
        }

        return $this->actingAs($this->user)->json('POST', "/teams/{$team}/assistant", ['messages' => [$message]], ['Accept' => 'application/json, text/event-stream']);
    };

    // The member asks; the turn pauses on the card. The parts of the stream.
    $this->ask = fn (): array => Parts::of(($this->send)(['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete my launch notes.']]])
        ->assertOk()
        ->streamedContent());

    // The member's answer to a call, call_1 unless named, as the /ai-sdk transport posts it, continuing the paused message.
    $this->answer = fn (bool $approved, string $messageId = 'msg-a1', string $team = 'acme', string $call = 'call_1', bool $newWorker = true): TestResponse => ($this->send)([
        'id' => $messageId,
        'role' => 'assistant',
        'parts' => [['type' => 'tool-delete-post', 'toolCallId' => $call, 'state' => 'approval-responded', 'approval' => ['id' => $call, 'approved' => $approved]]],
    ], $team, $newWorker);

    $this->conversation = fn (): string => (string) DB::table('agent_conversations')->value('id');
    $this->claimKey = fn (): string => ApprovalClaims::PREFIX.hash('sha256', ($this->conversation)()."\ncall_1");

    // What the model read for call_1, as the stored turn records it.
    $this->modelRead = function (): ?string {
        foreach (json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->latest('id')->value('steps'), true) as $step) {
            foreach ($step['tool_calls'] as $call) {
                if ($call['id'] === 'call_1') {
                    return $call['result'] ?? null;
                }
            }
        }

        return null;
    };

    $this->postExists = fn (): bool => Post::query()->whereKey($this->post->id)->exists();
});

describe('the copilot deletes only after the member confirms', function () {
    it('pauses with nothing run, and streams a card that shows the post', function () {
        $parts = ($this->ask)();

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([])
            ->and(collect($parts)->firstWhere('type', 'tool-input-available'))->toBe(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => 'delete-post', 'input' => []])
            ->and(collect($parts)->firstWhere('type', 'tool-approval-request'))->toBe(['type' => 'tool-approval-request', 'toolCallId' => 'call_1', 'approvalId' => 'call_1'])
            ->and(collect($parts)->firstWhere('type', 'data-approval'))->toBe(['type' => 'data-approval', 'id' => 'approval:call_1', 'data' => [
                'action' => 'delete-post',
                'effect' => 'destructive',
                'label' => 'Waiting for your confirmation',
                'title' => 'Delete this post? This cannot be undone.',
                'summary' => [['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']],
                'confirm' => 'Confirm',
                'decline' => 'Decline',
            ]])
            ->and($this->claimWrites)->toBe([($this->claimKey)()])
            ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('status'))->toBe('paused');

        Exceptions::assertNothingReported();
    });

    it('deletes the post once on Confirm, as the member, with a done row that touches posts', function () {
        ($this->ask)();

        $parts = Parts::of(($this->answer)(true)->assertOk()->streamedContent());

        expect(($this->postExists)())->toBeFalse()
            ->and($this->completed)->toBe(['delete-post'])
            ->and($parts[0])->toBe(['type' => 'start', 'messageId' => 'msg-a1'])
            ->and(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Removed', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['posts']]])
            ->and(collect($parts)->firstWhere('type', 'tool-output-available'))->toBe(['type' => 'tool-output-available', 'toolCallId' => 'call_1', 'output' => null])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.done'))
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();

        Exceptions::assertNothingReported();
    });

    it('never deletes on Decline: the row reads declined and the model reads the declined sentence', function () {
        ($this->ask)();

        $parts = Parts::of(($this->answer)(false)->assertOk()->streamedContent());

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([])
            ->and(collect($parts)->firstWhere('type', 'tool-output-denied'))->toBe(['type' => 'tool-output-denied', 'toolCallId' => 'call_1'])
            ->and(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Declined', 'status' => 'declined']])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.declined'))
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();

        // Nothing waits any more, so a later Confirm is stale.
        expect(($this->answer)(true)->status())->toBe(409)
            ->and(($this->postExists)())->toBeTrue();
    });
});

describe('a replayed answer runs nothing', function () {
    it('answers the same answer again with 409 and the one sentence', function () {
        ($this->ask)();
        ($this->answer)(true)->assertOk()->streamedContent();

        ($this->answer)(true)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect($this->completed)->toBe(['delete-post']);
    });
});

describe('two answers sent at once run the action once', function () {
    it('answers the second of two answers sent at once with 409 before any agent work, and keeps what ran', function () {
        ($this->ask)();

        // The first request has read the answer and holds the turn; its stream runs once the second request is done.
        $first = ($this->answer)(true)->assertOk();
        $stored = DB::table('agent_conversation_messages')->get()->toJson();
        $steps = $this->gateway->steps();

        ($this->answer)(true, newWorker: false)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect($this->gateway->steps())->toBe($steps)
            ->and(DB::table('agent_conversation_messages')->get()->toJson())->toBe($stored)
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([]);

        $parts = Parts::of($first->streamedContent());

        expect($this->completed)->toBe(['delete-post'])
            ->and($this->refused)->toBe([])
            ->and(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Removed', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['posts']]])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.done'))
            ->and(($this->postExists)())->toBeFalse();

        Exceptions::assertNothingReported();
    });

    it('answers new words sent while a Confirm holds the turn with 409 before any agent work, and keeps what ran', function () {
        ($this->ask)();

        $first = ($this->answer)(true)->assertOk();
        $stored = DB::table('agent_conversation_messages')->get()->toJson();
        $steps = $this->gateway->steps();

        ($this->send)(['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Never mind, keep it.']]], newWorker: false)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect($this->gateway->steps())->toBe($steps)
            ->and(DB::table('agent_conversation_messages')->get()->toJson())->toBe($stored)
            ->and($this->completed)->toBe([]);

        $parts = Parts::of($first->streamedContent());

        expect($this->completed)->toBe(['delete-post'])
            ->and(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Removed', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['posts']]])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.done'))
            ->and(DB::table('agent_conversation_messages')->where('role', 'user')->count())->toBe(1)
            ->and(($this->postExists)())->toBeFalse();

        Exceptions::assertNothingReported();
    });

    it('answers a Confirm sent while new words hold the turn with 409 before any agent work, and deletes nothing', function () {
        ($this->ask)();

        $words = ($this->send)(['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Never mind, keep it.']]])->assertOk();
        $stored = DB::table('agent_conversation_messages')->get()->toJson();
        $steps = $this->gateway->steps();

        ($this->answer)(true, newWorker: false)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect($this->gateway->steps())->toBe($steps)
            ->and(DB::table('agent_conversation_messages')->get()->toJson())->toBe($stored)
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([]);

        $words->streamedContent();

        expect($this->completed)->toBe([])
            ->and($this->gateway->steps())->toBe($steps + 1)
            ->and(DB::table('agent_conversation_messages')->where('role', 'user')->count())->toBe(2)
            ->and(($this->postExists)())->toBeTrue();

        // The words went on past the card, so a later Confirm is stale too.
        ($this->answer)(true)->assertStatus(409);

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([]);

        Exceptions::assertNothingReported();
    });

    it('still runs the action once when a resume gets past the reservation: step 8 refuses it', function () {
        ($this->ask)();

        // A second request that read the paused turn and the post before the first one ran, and reserved the turn only
        // after the first one ended: both are written back after the first resume, so the second resume reaches step 8
        // with everything else as it was.
        $paused = (array) DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();
        $post = $this->post->getAttributes();

        ($this->answer)(true)->assertOk()->streamedContent();

        DB::table('agent_conversation_messages')->where('id', $paused['id'])->update($paused);
        DB::table('posts')->insert($post);
        $this->refused = [];

        $parts = Parts::of(($this->answer)(true)->assertOk()->streamedContent());

        expect($this->completed)->toBe(['delete-post'])
            ->and($this->refused)->toBe([['delete-post', 409]])
            ->and(Parts::rows($parts))->toHaveCount(1)
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'delete-post', 'status' => 'refused'])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(($this->postExists)())->toBeTrue();
    });

    it('keeps the turn while a confirmed call runs past a minute: a second Confirm then gets 409, and the conversation keeps what ran', function () {
        ($this->ask)();

        // The confirmed call took just over a minute, and a second Confirm arrives before its result is stored. Any
        // stream it gets runs to its end, as on another worker.
        $second = null;
        Event::listen(ActionCompleted::class, function () use (&$second): void {
            if ($second === null) {
                $this->travel(61)->seconds();
                $second = ($this->answer)(true, newWorker: false);
                $second->baseResponse instanceof StreamedResponse && $second->streamedContent();
            }
        });

        $parts = Parts::of(($this->answer)(true)->assertOk()->streamedContent());

        expect($second?->getStatusCode())->toBe(409)
            ->and($this->completed)->toBe(['delete-post'])
            ->and($this->refused)->toBe([])
            ->and(Parts::types($parts))->not->toContain('error')
            ->and(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Removed', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['posts']]])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.done'))
            ->and(($this->postExists)())->toBeFalse();

        Exceptions::assertNothingReported();
    });

    it('ends the reservation with the resume, so a later answer to another waiting turn resumes as usual', function () {
        // Whether the turn waiting on the call is free: a probe takes its lock and gives it back.
        $free = function (string $call): bool {
            $probe = Cache::lock(ApprovalClaims::TURN_PREFIX.hash('sha256', ($this->conversation)()."\n".$call), 1);

            return $probe->get() && $probe->release();
        };

        // At each run of the action: whether the turns waiting on call_1 and on call_2 are free.
        $running = [];
        Event::listen(ActionCompleted::class, function () use (&$running, $free): void {
            $running[] = [$free('call_1'), $free('call_2')];
        });

        ($this->ask)();
        ($this->answer)(true)->assertOk()->streamedContent();

        expect($running)->toBe([[false, true]])
            ->and($free('call_1'))->toBeTrue();

        // The model asks again in the same conversation, about another post, on a call of its own.
        $roadmap = Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'Roadmap', 'status' => 'draft']);
        (new ScriptedGateway([['delete-post', ['post' => $roadmap->id], 'call_2']], 'Done as you asked.', 'I will ask you first.'))->install();

        ($this->send)(['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete my roadmap too.']]])->assertOk()->streamedContent();

        $parts = Parts::of(($this->answer)(true, 'msg-a2', call: 'call_2')->assertOk()->streamedContent());

        expect(Parts::rows($parts))->toBe([['action' => 'delete-post', 'label' => 'Removed', 'status' => 'done', 'effect' => 'destructive', 'touches' => ['posts']]])
            ->and($this->completed)->toBe(['delete-post', 'delete-post'])
            ->and(Post::query()->whereKey($roadmap->id)->exists())->toBeFalse()
            ->and($running)->toBe([[false, true], [true, false]])
            ->and($free('call_2'))->toBeTrue();

        Exceptions::assertNothingReported();
    });

    it('keeps the turn of a resume that never ended for approvals.ttl, when its confirmation has lapsed too: the next answer then runs nothing', function () {
        ($this->ask)();

        // The request read the answer and reserved the turn, then its worker died before the stream ran.
        ($this->answer)(true)->assertOk();

        $this->travel(29)->minutes();

        ($this->answer)(true)->assertStatus(409);
        ($this->send)(['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Are you there?']]])->assertStatus(409);

        $this->travel(61)->seconds();

        $parts = Parts::of(($this->answer)(true)->assertOk()->streamedContent());

        expect(Parts::rows($parts)[0])->toMatchArray(['action' => 'delete-post', 'status' => 'refused'])
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([['delete-post', 409]])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and(($this->postExists)())->toBeTrue();
    });
});

describe('an answer after the TTL runs nothing, and mints nothing', function () {
    it('refuses a confirmation older than approvals.ttl: the row reads refused and the model hears why', function () {
        ($this->ask)();

        $this->travel(31)->minutes();
        $this->claimWrites = [];

        $parts = Parts::of(($this->answer)(true)->assertOk()->streamedContent());

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([['delete-post', 409]])
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'delete-post', 'status' => 'refused', 'note' => trans('agentic-actions::activity.refused')])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.not_confirmed'))
            ->and($this->claimWrites)->toBe([])
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();
    });

    it('honours a shorter approvals.ttl', function () {
        config(['agentic-actions.approvals.ttl' => 60]);

        ($this->ask)();
        $this->travel(61)->seconds();

        ($this->answer)(true)->assertOk()->streamedContent();

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([]);
    });

    it('runs nothing once the claim is gone, and no answer brings it back', function () {
        ($this->ask)();

        Cache::forget(($this->claimKey)());
        $this->claimWrites = [];

        ($this->answer)(true)->assertOk()->streamedContent();

        expect(($this->postExists)())->toBeTrue()
            ->and($this->claimWrites)->toBe([])
            ->and(Cache::has(($this->claimKey)()))->toBeFalse();
    });
});

describe('a reload restores the card', function () {
    it('returns the paused turn with the card rebuilt from the post, and answering it deletes the post', function () {
        ($this->ask)();

        app()->forgetScopedInstances();
        $response = $this->actingAs($this->user)->getJson('/teams/acme/assistant')->assertOk();
        $paused = $response->json('messages.1');

        expect($response->json('messages'))->toHaveCount(2)
            ->and($paused['role'])->toBe('assistant')
            ->and($paused['parts'][0])->toBe(['type' => 'text', 'text' => 'I will ask you first.'])
            ->and($paused['parts'][1])->toBe(['type' => 'tool-delete-post', 'toolCallId' => 'call_1', 'state' => 'approval-requested', 'input' => [], 'approval' => ['id' => 'call_1']])
            ->and($paused['parts'][2]['data']['summary'])->toBe([['label' => 'Post', 'value' => 'Launch notes'], ['label' => 'Status', 'value' => 'draft']])
            ->and($response->getContent())->toContain('"input":{}');

        $parts = Parts::of(($this->answer)(true, $paused['id'])->assertOk()->streamedContent());

        expect($parts[0])->toBe(['type' => 'start', 'messageId' => $paused['id']])
            ->and(($this->postExists)())->toBeFalse()
            ->and($this->completed)->toBe(['delete-post']);

        app()->forgetScopedInstances();
        expect(collect($this->getJson('/teams/acme/assistant')->json('messages'))->pluck('parts')->flatten(1)->pluck('type')->all())
            ->not->toContain('data-approval');
    });

    it('restores no card once the post reads otherwise than the card showed, and a Confirm of the first card runs nothing', function () {
        ($this->ask)();
        $this->post->update(['title' => 'Launch notes, final']);

        app()->forgetScopedInstances();
        $response = $this->actingAs($this->user)->getJson('/teams/acme/assistant')->assertOk();

        expect($response->json('messages.1.parts'))->toBe([['type' => 'text', 'text' => 'I will ask you first.']]);

        $parts = Parts::of(($this->answer)(true, (string) $response->json('messages.1.id'))->assertOk()->streamedContent());

        expect(($this->postExists)())->toBeTrue()
            ->and($this->completed)->toBe([])
            ->and($this->refused)->toBe([['delete-post', 409]])
            ->and(Parts::rows($parts)[0])->toMatchArray(['action' => 'delete-post', 'status' => 'refused'])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.not_confirmed'));
    });
});
