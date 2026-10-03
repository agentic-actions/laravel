<?php

use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Events\ActionCompleted;
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
 * Forms on the workbench, and the attacks around a waiting form: a replay, two answers at
 * once, another member, another team, a token, a late answer, keys outside the form, a bare approval, a form's answer
 * for a card's call, and a flood of checks. The model is scripted on the provider, so every resume runs laravel/ai's
 * own resume path.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Exceptions::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->users()->attach($this->user);

    $this->completed = [];
    Event::listen(ActionCompleted::class, function (ActionCompleted $event): void {
        $this->completed[] = $event->action;
    });

    $this->gateway = (new ScriptedGateway([['draft-team-post', ['title' => 'Launch notes']]], 'Saved as you asked.', 'I can draft it once you add a few details.'))->install();

    // One request to a team's assistant, as the given person's own session. $newWorker false keeps the scoped instances,
    // for a request sent while an earlier one's stream, which still reads them, has not run yet.
    $this->send = function (array $message, string $team = 'acme', ?User $as = null, array $headers = [], bool $newWorker = true): TestResponse {
        if ($newWorker) {
            app()->forgetScopedInstances();
            app('auth')->forgetGuards();
        }

        return $this->actingAs($as ?? $this->user)->json('POST', "/teams/{$team}/assistant", ['messages' => [$message]], ['Accept' => 'application/json, text/event-stream', ...$headers]);
    };

    $this->ask = fn (): string => ($this->send)(['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Draft a team post called Launch notes.']]])
        ->assertOk()
        ->streamedContent();

    $this->valid = ['title' => 'Launch notes', 'body' => 'What shipped this week.', 'status' => 'draft'];

    // An answer to a call, as the /ai-sdk transport posts it; $result null sends a bare approval.
    $this->answerBody = fn (?array $result, bool $approved = true, string $call = 'call_1', string $tool = 'draft-team-post'): array => [
        'id' => 'msg-a1',
        'role' => 'assistant',
        'parts' => [[
            'type' => 'tool-'.$tool,
            'toolCallId' => $call,
            'state' => 'approval-responded',
            'approval' => ['id' => $call, 'approved' => $approved],
            ...($result === null ? [] : ['elicitation' => $result]),
        ]],
    ];

    $this->accept = fn (array $content, string $team = 'acme', ?User $as = null, bool $precognition = false, bool $newWorker = true): TestResponse => ($this->send)(
        ($this->answerBody)(['action' => 'accept', 'content' => $content]),
        $team,
        $as,
        $precognition ? ['Precognition' => 'true'] : [],
        $newWorker,
    );

    $this->conversation = fn (): string => (string) DB::table('agent_conversations')->value('id');
    $this->claimKey = fn (string $call = 'call_1'): string => ApprovalClaims::PREFIX.hash('sha256', ($this->conversation)()."\n".$call);

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
});

describe('an answer runs once, for the person the form was built for', function () {
    it('answers the same answer again with 409, and drafts one post', function () {
        ($this->ask)();
        ($this->accept)($this->valid)->assertOk()->streamedContent();

        ($this->accept)($this->valid)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Post::query()->count())->toBe(1)
            ->and($this->completed)->toBe(['draft-team-post']);
    });

    it('answers the second of two answers sent at once with 409 before any agent work, and drafts one post', function () {
        ($this->ask)();

        // The first request has read the answer and holds the turn; its stream runs once the second request is done.
        $first = ($this->accept)($this->valid)->assertOk();
        $steps = $this->gateway->steps();

        ($this->accept)($this->valid, newWorker: false)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect($this->gateway->steps())->toBe($steps)
            ->and(Post::query()->count())->toBe(0);

        $first->streamedContent();

        expect(Post::query()->count())->toBe(1)
            ->and($this->completed)->toBe(['draft-team-post']);
    });

    it('answers another member\'s session with 409, checked or not, and leaves the claim', function (bool $precognition) {
        ($this->ask)();

        $bob = User::factory()->create();
        $this->team->users()->attach($bob);

        ($this->accept)($this->valid, 'acme', $bob, $precognition)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    })->with(['the check' => true, 'the answer' => false]);

    it('answers the same person from another team\'s page with 409, since each team keeps its own conversation', function () {
        ($this->ask)();

        $beta = Team::factory()->create(['slug' => 'beta']);
        $beta->users()->attach($this->user);

        ($this->accept)($this->valid, 'beta')->assertStatus(409);

        $reload = function (string $team): array {
            app()->forgetScopedInstances();
            app('auth')->forgetGuards();

            return $this->actingAs($this->user)->getJson("/teams/{$team}/assistant")->assertOk()->json('messages');
        };

        // Acme's words and waiting form reload on acme's page only; its claim still waits there.
        expect($reload('beta'))->toBe([])
            ->and($reload('acme'))->not->toBe([])
            ->and(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });

    it('never takes an answer from a token, even the member\'s own', function () {
        ($this->ask)();

        $token = $this->user->createToken('client', ['*'])->plainTextToken;

        app()->forgetScopedInstances();
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->postJson('/teams/acme/assistant', ['messages' => [($this->answerBody)(['action' => 'accept', 'content' => $this->valid])]])
            ->assertUnauthorized();

        foreach ([[], ['Precognition' => 'true']] as $headers) {
            app()->forgetScopedInstances();
            app('auth')->forgetGuards();

            $this->withToken($token)
                ->postJson('/api/teams/acme/assistant', ['messages' => [($this->answerBody)(['action' => 'accept', 'content' => $this->valid])]], $headers)
                ->assertStatus(409)
                ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);
        }

        expect(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });

    it('answers an answer after approvals.ttl with 409 and drafts nothing: the lapsed claim no longer holds', function () {
        ($this->ask)();

        $this->travel(31)->minutes();

        ($this->accept)($this->valid)
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Post::query()->count())->toBe(0)
            ->and($this->completed)->toBe([]);
    });
});

describe('only the form\'s own answer counts', function () {
    it('drops keys outside the form: the team, an offered field the form does not hold, and an unknown key', function () {
        $other = Team::factory()->create(['slug' => 'other']);

        ($this->ask)();
        ($this->accept)([...$this->valid, 'team_id' => $other->id, 'user_id' => 99, 'excerpt' => 'CANARY-EXCERPT', 'pinned' => true])->assertOk()->streamedContent();

        expect(Post::query()->sole()->only(['title', 'body', 'status', 'excerpt', 'team_id', 'user_id']))->toBe([...$this->valid, 'excerpt' => null, 'team_id' => $this->team->id, 'user_id' => $this->user->id])
            ->and(($this->modelRead)())->toBe('The person filled in: title, body, status. '.trans('agentic-actions::model.done'));
    });

    it('declines a bare approval of the form\'s call: nothing drafted, the claim spent, and the model reads the decline', function () {
        ($this->ask)();

        $parts = Parts::of(($this->send)(($this->answerBody)(null))->assertOk()->streamedContent());

        expect(Post::query()->count())->toBe(0)
            ->and($this->completed)->toBe([])
            ->and(Parts::rows($parts))->toBe([['action' => 'draft-team-post', 'label' => 'Declined', 'status' => 'declined']])
            ->and(($this->modelRead)())->toBe(trans('agentic-actions::model.declined'))
            ->and(Cache::has(($this->claimKey)()))->toBeFalse()
            ->and(Cache::has(($this->claimKey)().':claimed'))->toBeTrue();
    });

    it('takes no form\'s answer for the card of a Destructive call: 409, and the card still waits', function () {
        $post = Post::factory()->for($this->user)->forTeam($this->team)->create(['title' => 'Launch notes']);
        (new ScriptedGateway([['delete-post', ['post' => $post->id]]], 'Deleted it.', 'I will ask you first.'))->install();

        ($this->ask)();

        ($this->send)(($this->answerBody)(['action' => 'accept', 'content' => ['post' => $post->id]], tool: 'delete-post'))
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Post::query()->whereKey($post->id)->exists())->toBeTrue()
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });

    it('meets the chat route\'s throttle: forty checks in a minute get 429 after the twentieth', function () {
        ($this->ask)();

        $statuses = [];

        foreach (range(1, 40) as $ignored) {
            $statuses[] = ($this->accept)($this->valid, precognition: true)->status();
        }

        expect(array_count_values($statuses))->toBe([204 => 19, 429 => 21])
            ->and(Post::query()->count())->toBe(0)
            ->and(Cache::has(($this->claimKey)()))->toBeTrue();
    });
});
