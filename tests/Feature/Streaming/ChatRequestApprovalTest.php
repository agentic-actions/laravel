<?php

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Runner;
use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ChatRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\ToolCall;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Actions\Trace;
use Tests\Fixtures\Approvals\ConfirmedDelete;
use Tests\Fixtures\Approvals\ForgetfulStore;
use Tests\Fixtures\Approvals\HostConfirmedTool;
use Tests\Fixtures\Approvals\HostToolAgent;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Models\User;

/*
 * The answer to a confirmation: per waiting tool-call id, approve or decline and an optional reason, and
 * nothing else, read only from a session of the person the server-chosen conversation belongs to. Every decision is
 * built here, never an edit; a declined call's claim is spent before any agent work; a stale answer gets one sentence.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);

    Trace::reset();

    $this->user = User::factory()->create();

    // Pause the user's host-tool agent on the scripted calls; the conversation's id.
    $this->pause = function (array $calls = [[HostConfirmedTool::NAME, ['note' => 'x']]], ?User $as = null): string {
        (new ScriptedGateway($calls, 'Sent.'))->install();

        $as ??= $this->user;

        return (string) (new HostToolAgent($as))->forUser($as)->prompt('Send a note.')->conversationId;
    };

    // The agent the host's route builds: continued in the conversation, for the person.
    $this->agent = fn (string $conversationId, ?User $as = null): HostToolAgent => (new HostToolAgent($as ?? $this->user))->continue($conversationId, as: $as ?? $this->user);

    // One answered tool part, as the transport sends it.
    $this->answer = fn (string $id = 'call_1', mixed $approved = true, array $approval = [], array $part = []): array => [
        'type' => 'tool-'.HostConfirmedTool::NAME,
        'toolCallId' => $id,
        'state' => 'approval-responded',
        ...$part,
        'approval' => ['id' => $id, 'approved' => $approved, ...$approval],
    ];

    // A body whose last message is the assistant's, holding the given parts.
    $this->body = fn (array $parts, mixed $id = 'msg-a1'): array => ['messages' => [['id' => $id, 'role' => 'assistant', 'parts' => $parts]]];

    // ChatRequest::from() over a JSON body, as the given user (null: no user).
    $this->read = function (array $body, ?HostToolAgent $agent, ?User $as = null, bool $signedIn = true): ChatRequest {
        $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $user = $signedIn ? ($as ?? $this->user) : null;
        $request->setUserResolver(fn (): ?User => $user);

        return ChatRequest::from($request, $agent);
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

/**
 * Each decision's action and result, by id.
 *
 * @return array<string, array{0: string, 1: ?string}>
 */
function chatDecisions(ChatRequest $chat): array
{
    return array_map(fn (Decision $decision): array => [$decision->action, $decision->result], $chat->decisions()?->all() ?? []);
}

/**
 * Whether a request reads as stale: no decision, no message id, and respond() answers 409 without running the turn.
 */
function chatIsStale(ChatRequest $chat): bool
{
    $ran = false;
    $response = $chat->respond(function () use (&$ran) {
        $ran = true;

        return response()->noContent();
    });

    return $chat->decisions() === null && $chat->messageId() === null && ! $ran && $response->getStatusCode() === 409;
}

describe('what it reads', function () {
    it('reads approve and decline for the calls the conversation waits on, and declines the rest', function () {
        $id = ($this->pause)([[HostConfirmedTool::NAME], [HostConfirmedTool::NAME], [HostConfirmedTool::NAME]]);

        $chat = ($this->read)(($this->body)([($this->answer)('call_1', true), ($this->answer)('call_2', false)]), ($this->agent)($id));

        expect(chatDecisions($chat))->toBe([
            'call_1' => ['approve', null],
            'call_2' => ['reject', trans('agentic-actions::model.declined')],
            '*' => ['reject', trans('agentic-actions::model.declined')],
        ])
            ->and($chat->message())->toBeNull()
            ->and($chat->isEmpty())->toBeFalse()
            ->and($chat->messageId())->toBe('msg-a1');
    });

    it('resumes with the answered call run and the unanswered one declined', function () {
        $id = ($this->pause)([[HostConfirmedTool::NAME], [HostConfirmedTool::NAME]]);
        $agent = ($this->agent)($id);

        $response = $agent->prompt(($this->read)(($this->body)([($this->answer)('call_1', true)]), $agent));

        expect(Trace::$calls)->toBe(['HostConfirmedTool::handle'])
            ->and($response->hasPendingApprovals())->toBeFalse()
            ->and(app(ConversationStore::class)->pendingApprovalsFor($id))->toBe([]);
    });

    it('frames the person\'s reason for a decline, in the request\'s locale', function () {
        $id = ($this->pause)();

        app()->setLocale('ar');

        $chat = ($this->read)(($this->body)([($this->answer)('call_1', false, ['reason' => '  ليس الآن  '])]), ($this->agent)($id));

        expect(chatDecisions($chat)['call_1'])->toBe(['reject', trans('agentic-actions::model.declined_because', ['reason' => '"ليس الآن"'], 'ar')])
            ->and(chatDecisions($chat)['call_1'][1])->toStartWith('رفض الشخص');
    });

    it('drops a reason over the limit or not UTF-8, and still declines', function (string $reason) {
        config(['agentic-actions.agents.max_message_length' => 10]);

        $id = ($this->pause)();

        $request = Request::create('/assistant', 'POST', ['messages' => [['id' => 'msg-a1', 'role' => 'assistant', 'parts' => [($this->answer)('call_1', false, ['reason' => $reason])]]]]);
        $request->setUserResolver(fn (): User => $this->user);

        expect(chatDecisions(ChatRequest::from($request, ($this->agent)($id)))['call_1'])->toBe(['reject', trans('agentic-actions::model.declined')]);
    })->with([
        'over the limit' => [str_repeat('ب', 11)],
        'not UTF-8' => ["\xB1\x31"],
        'blank' => ['   '],
    ]);

    it('counts the first answer for an id', function () {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->answer)('call_1', false), ($this->answer)('call_1', true)]), ($this->agent)($id));

        expect(chatDecisions($chat)['call_1'])->toBe(['reject', trans('agentic-actions::model.declined')]);
    });

    it('reads a numeric id as a string, and its decision resolves the waiting call', function () {
        Ai::textProvider()->useTextGateway(new OneStepGateway([new ToolCall('7', HostConfirmedTool::NAME, ['note' => 'x'])], 'Sent.'));

        $id = (string) (new HostToolAgent($this->user))->forUser($this->user)->prompt('Send a note.')->conversationId;
        $agent = ($this->agent)($id);
        $chat = ($this->read)(($this->body)([($this->answer)('7', true)]), $agent);

        expect(chatDecisions($chat))->toHaveKey('7')
            ->and($chat->decisions()?->get('7')?->isApproved())->toBeTrue();

        $agent->prompt($chat);

        expect(Trace::$calls)->toBe(['HostConfirmedTool::handle'])
            ->and(app(ConversationStore::class)->pendingApprovalsFor($id))->toBe([]);
    });

    it('never reads input, output, the tool name or arguments, so no decision is ever an edit', function (array $part, array $approval) {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->answer)('call_1', true, $approval, $part)]), ($this->agent)($id));

        foreach ($chat->decisions()?->all() ?? [] as $decision) {
            expect($decision->isEdited())->toBeFalse()
                ->and($decision->arguments)->toBeNull();
        }

        expect(chatDecisions($chat)['call_1'])->toBe(['approve', null]);
    })->with([
        'input' => [['input' => ['note' => 'EDITED']], []],
        'output' => [['output' => 'EDITED', 'errorText' => 'EDITED'], []],
        'a tool name' => [['toolName' => 'confirmed-delete', 'type' => 'dynamic-tool'], []],
        'approval arguments' => [[], ['arguments' => ['note' => 'EDITED']]],
        'an edit action' => [[], ['action' => 'edit', 'decision' => 'edit', 'edited' => ['note' => 'EDITED']]],
        'everything at once' => [['input' => ['note' => 'EDITED'], 'output' => 'x', 'toolName' => 'x', 'arguments' => ['note' => 'EDITED']], ['arguments' => ['note' => 'EDITED'], 'input' => ['note' => 'EDITED']]],
    ]);

    it('reads an assistant message as empty without an agent, as before', function () {
        $id = ($this->pause)();

        $chat = ($this->read)(($this->body)([($this->answer)('call_1', true)]), null);

        expect($chat->isEmpty())->toBeTrue()
            ->and($chat->decisions())->toBeNull()
            ->and($chat->messageId())->toBeNull()
            ->and($chat->respond(fn () => response()->noContent())->getStatusCode())->toBe(422)
            ->and(app(ConversationStore::class)->pendingApprovalsFor($id))->toHaveCount(1);
    });

    it('keeps the message id only when it is a string of 1 to 255 characters', function (mixed $messageId, ?string $expected) {
        $id = ($this->pause)();

        expect(($this->read)(($this->body)([($this->answer)()], $messageId), ($this->agent)($id))->messageId())->toBe($expected);
    })->with([
        'the longest' => [str_repeat('m', 255), str_repeat('m', 255)],
        'too long' => [str_repeat('m', 256), null],
        'empty' => ['', null],
        'a number' => [7, null],
        'missing' => [null, null],
    ]);
});

describe('what reads as stale', function () {
    it('reads answers that match no waiting call as stale', function (Closure $parts) {
        $id = ($this->pause)();

        expect(chatIsStale(($this->read)(($this->body)($parts->call($this)), ($this->agent)($id))))->toBeTrue();
    })->with([
        'an id that is not waiting' => [fn (): array => [($this->answer)('call_9', true)]],
        'the wildcard id' => [fn (): array => [($this->answer)('*', true)]],
        'approved as 1' => [fn (): array => [($this->answer)('call_1', 1)]],
        'approved as "true"' => [fn (): array => [($this->answer)('call_1', 'true')]],
        'no approved' => [fn (): array => [['type' => 'tool-'.HostConfirmedTool::NAME, 'toolCallId' => 'call_1', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1']]]],
        'another state' => [fn (): array => [($this->answer)('call_1', true, part: ['state' => 'approval-requested'])]],
        'a part that is not a tool part' => [fn (): array => [['type' => 'text', 'text' => 'yes', 'state' => 'approval-responded', 'approval' => ['id' => 'call_1', 'approved' => true]]]],
        'an id over 255 characters' => [fn (): array => [($this->answer)(str_repeat('c', 256), true)]],
        'no parts' => [fn (): array => []],
    ]);

    it('reads the answer of an agent with no conversation yet as stale, and raises nothing', function (Closure $agent) {
        ($this->pause)();

        expect(chatIsStale(($this->read)(($this->body)([($this->answer)()]), $agent->call($this))))->toBeTrue();
    })->with([
        'after forUser()' => [fn (): HostToolAgent => (new HostToolAgent($this->user))->forUser($this->user)],
        'after continueLastConversation() finds none' => [fn (): HostToolAgent => (new HostToolAgent($other = User::factory()->create()))->continueLastConversation($other)],
    ]);

    it('reads as stale for a request with no user, or whose context has no guard', function () {
        $id = ($this->pause)();

        expect(chatIsStale(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id), signedIn: false)))->toBeTrue();

        config(['auth.defaults.guard' => '']);

        expect(ActionContext::fromRequest(Request::create('/'))->guard)->toBeNull()
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id))))->toBeTrue();
    });

    it('reads as stale for a person the conversation does not belong to, whoever the agent was built for', function () {
        $id = ($this->pause)();
        $other = User::factory()->create();

        expect(chatIsStale(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id, $other), $other)))->toBeTrue()
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id), $other)))->toBeTrue();
    });

    it('reads as stale for an agent that cannot pause', function (Closure $agent) {
        $id = ($this->pause)();

        expect(chatIsStale(($this->read)(($this->body)([($this->answer)()]), $agent->call($this, $id))))->toBeTrue();
    })->with([
        'no participant' => [fn (string $id): HostToolAgent => (new HostToolAgent($this->user))->continue($id)],
        'a store that cannot read pending calls' => [function (string $id): HostToolAgent {
            app()->instance(ConversationStore::class, new ForgetfulStore);

            return ($this->agent)($id);
        }],
    ]);

    it('reads as stale once the conversation waits on nothing', function () {
        $id = ($this->pause)();

        ($this->agent)($id)->prompt(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id)));

        expect(Trace::$calls)->toBe(['HostConfirmedTool::handle'])
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id))))->toBeTrue();
    });
});

describe('spending claims', function () {
    it('spends the claim of every waiting call it does not approve, answered or not, before any agent work', function () {
        $id = ($this->pause)([[HostConfirmedTool::NAME], [HostConfirmedTool::NAME], [HostConfirmedTool::NAME]]);
        $post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
        $claims = app(ApprovalClaims::class);

        // A claim for each paused id, minted from the first request's preview of a real card.
        $cards = [];

        foreach (['call_1', 'call_2', 'call_3'] as $call) {
            $cards[$call] = app(Runner::class)->preview(ClassExposure::of(ConfirmedDelete::class), ['post' => $post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket($id, $call)), ['approvals']);

            expect($cards[$call])->toBeInstanceOf(ApprovalCard::class)
                ->and($claims->mint($id, $call, $cards[$call]))->toBeTrue();
        }

        Trace::reset();

        ($this->read)(($this->body)([($this->answer)('call_1', true), ($this->answer)('call_2', false)]), ($this->agent)($id));

        $claim = fn (string $call): bool => $claims->claim($cards[$call]);

        expect($claim('call_2'))->toBeFalse()
            ->and($claim('call_3'))->toBeFalse()
            ->and($claim('call_1'))->toBeTrue()
            ->and(Trace::$calls)->toBe([]);
    });

    it('spends nothing for a stale body or a user message', function () {
        $id = ($this->pause)();
        $post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
        $card = app(Runner::class)->preview(ClassExposure::of(ConfirmedDelete::class), ['post' => $post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket($id, 'call_1')), ['approvals']);

        app(ApprovalClaims::class)->mint($id, 'call_1', $card);

        ($this->read)(($this->body)([($this->answer)('call_9', false)]), ($this->agent)($id));
        ($this->read)(['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Never mind.']]]]], ($this->agent)($id));

        expect(app(ApprovalClaims::class)->claim($card))->toBeTrue();
    });

    it('spends nothing for answers read while another request holds the turn, a decline included', function () {
        $id = ($this->pause)();
        $post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
        $card = app(Runner::class)->preview(ClassExposure::of(ConfirmedDelete::class), ['post' => $post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket($id, 'call_1')), ['approvals']);

        app(ApprovalClaims::class)->mint($id, 'call_1', $card);

        $first = ($this->read)(($this->body)([($this->answer)('call_1', true)]), ($this->agent)($id));

        expect(chatIsStale(($this->read)(($this->body)([($this->answer)('call_1', false)]), ($this->agent)($id))))->toBeTrue()
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)('call_1', true)]), ($this->agent)($id))))->toBeTrue()
            ->and($first->decisions()?->get('call_1')?->isApproved())->toBeTrue()
            ->and(app(ApprovalClaims::class)->claim($card))->toBeTrue();
    });
});

describe('new words while a turn waits', function () {
    beforeEach(function () {
        // A body whose last message is the person's words.
        $this->words = fn (string $text = 'Never mind.'): array => ['messages' => [['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $text]]]]];
    });

    it('reserves the waiting turn for new words, so an answer read meanwhile is stale and spends nothing', function () {
        $id = ($this->pause)();
        $post = $this->user->posts()->create(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
        $card = app(Runner::class)->preview(ClassExposure::of(ConfirmedDelete::class), ['post' => $post->id], ActionContext::agent($this->user)->withApproval(new ApprovalTicket($id, 'call_1')), ['approvals']);

        app(ApprovalClaims::class)->mint($id, 'call_1', $card);

        $words = ($this->read)(($this->words)(), ($this->agent)($id));

        expect($words->message()?->content)->toBe('Never mind.')
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)('call_1', false)]), ($this->agent)($id))))->toBeTrue()
            ->and(chatIsStale(($this->read)(($this->body)([($this->answer)('call_1', true)]), ($this->agent)($id))))->toBeTrue()
            ->and(app(ApprovalClaims::class)->claim($card))->toBeTrue();
    });

    it('reads new words as stale while an answer holds the turn, and runs nothing', function () {
        $id = ($this->pause)();

        $answer = ($this->read)(($this->body)([($this->answer)()]), ($this->agent)($id));
        $words = ($this->read)(($this->words)(), ($this->agent)($id));

        expect($words->message())->toBeNull()
            ->and(chatIsStale($words))->toBeTrue()
            ->and($answer->decisions()?->get('call_1')?->isApproved())->toBeTrue();
    });

    it('ends the reservation of new words when their turn ends', function () {
        $id = ($this->pause)();

        $response = ($this->read)(($this->words)(), ($this->agent)($id))->respond(fn () => response()->noContent());

        expect($response->getStatusCode())->toBe(204)
            ->and(app(ApprovalClaims::class)->reserve($id, ['call_1']))->not->toBeNull();
    });

    it('reserves nothing for new words without the agent, or once the conversation waits on nothing', function () {
        $id = ($this->pause)();

        expect(($this->read)(($this->words)(), null)->message())->not->toBeNull()
            ->and(($this->read)(($this->words)(), null)->message())->not->toBeNull();

        $agent = ($this->agent)($id);
        $agent->prompt(($this->read)(($this->body)([($this->answer)()]), $agent));

        expect(app(ConversationStore::class)->pendingApprovalsFor($id))->toBe([])
            ->and(($this->read)(($this->words)(), ($this->agent)($id))->message())->not->toBeNull()
            ->and(($this->read)(($this->words)(), ($this->agent)($id))->message())->not->toBeNull();
    });
});

describe('the route', function () {
    beforeEach(function () {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->mountRoutes(function (): void {
            $turn = function (Request $request) {
                $user = $request->user();
                $agent = (new HostToolAgent($user))->continueLastConversation($user);
                $chat = ChatRequest::from($request, $agent);

                return $chat->respond(fn (ChatRequest $chat) => $agent->stream($chat)->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));
            };

            Route::middleware(['web', 'auth'])->post('/assistant', $turn);
            Route::middleware('auth:sanctum')->post('/api/assistant', $turn);
        });

        (new ScriptedGateway([[HostConfirmedTool::NAME, ['note' => 'x']]], 'Sent.'))->install();

        // The pause, through the route: the person asks, the model calls the host's tool, the turn waits.
        $paused = $this->actingAs($this->user)->postJson('/assistant', ['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Send a note.']]]]]);

        expect(Parts::types(Parts::of($paused->streamedContent())))->toContain('tool-approval-request');
    });

    it('keeps the turn while a confirmed call runs past a minute: the same answer then gets 409, and the call runs once', function () {
        $body = ($this->body)([($this->answer)()]);

        // The call took just over a minute, and the same answer arrives from another tab before its result is stored.
        // Any stream it gets runs to its end, as on another worker.
        $second = null;
        Event::listen(ToolInvoked::class, function () use (&$second, $body): void {
            if ($second === null) {
                $this->travel(61)->seconds();
                $second = $this->actingAs($this->user)->postJson('/assistant', $body);
                $second->baseResponse instanceof StreamedResponse && $second->streamedContent();
            }
        });

        $parts = Parts::of($this->actingAs($this->user)->postJson('/assistant', $body)->streamedContent());

        expect($second?->getStatusCode())->toBe(409)
            ->and(Trace::$calls)->toBe(['HostConfirmedTool::handle'])
            ->and(Parts::types($parts))->not->toContain('error');
    });

    it('turns answers found stale as the turn starts into the same 409', function () {
        $body = ($this->body)([($this->answer)()]);
        $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $this->user);

        // A request reads the answer and reserves the turn, then stalls until its reservation lapses; another request
        // reads the same answer and resumes the turn meanwhile.
        $agent = (new HostToolAgent($this->user))->continueLastConversation($this->user);
        $late = ChatRequest::from($request, $agent);

        $this->travel(config('agentic-actions.approvals.ttl') + 1)->seconds();
        $this->actingAs($this->user)->postJson('/assistant', $body)->streamedContent();

        $response = $late->respond(fn (ChatRequest $chat) => $agent->stream($chat)->usingProtocol(new ActionsProtocol(messageId: $chat->messageId())));

        expect($response->getStatusCode())->toBe(409)
            ->and(json_decode((string) $response->getContent(), true))->toBe(['message' => trans('agentic-actions::stream.stale')])
            ->and(Trace::$calls)->toBe(['HostConfirmedTool::handle']);
    });

    it('ends the reservation when a turn that is not streamed returns or throws', function () {
        $body = ($this->body)([($this->answer)()]);
        $request = Request::create('/assistant', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn (): User => $this->user);
        $agent = fn (): HostToolAgent => (new HostToolAgent($this->user))->continueLastConversation($this->user);
        $id = (string) $agent()->currentConversation();

        expect(fn () => ChatRequest::from($request, $agent())->respond(fn () => throw new RuntimeException('The provider is down.')))
            ->toThrow(RuntimeException::class, 'The provider is down.');

        // Not stale: the failed turn left the turn free, so the person's next press resumes it.
        $response = ChatRequest::from($request, $resumed = $agent())->respond(fn (ChatRequest $chat) => response()->json(['text' => $resumed->prompt($chat)->text]));

        expect($response->getStatusCode())->toBe(200)
            ->and(Trace::$calls)->toBe(['HostConfirmedTool::handle'])
            ->and(app(ApprovalClaims::class)->reserve($id, ['call_1']))->not->toBeNull();
    });

    it('answers an empty body with 422 and the one sentence', function () {
        $this->actingAs($this->user)->postJson('/assistant', ['messages' => []])
            ->assertStatus(422)
            ->assertExactJson(['message' => trans('agentic-actions::stream.empty', ['max' => 4000])]);
    });

    it('never approves from a form-encoded body or a query string', function (string $how) {
        $form = [];
        parse_str(http_build_query(($this->body)([($this->answer)()])), $form);

        $response = $how === 'form'
            ? $this->actingAs($this->user)->post('/assistant', $form)
            : $this->actingAs($this->user)->post('/assistant?'.http_build_query($form));

        $response->assertStatus(409)->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Trace::$calls)->toBe([]);
    })->with(['form', 'query']);

    it('holds the waiting turn for words a token sends, so the person\'s Confirm meanwhile gets 409 and runs nothing', function () {
        $token = $this->user->createToken('client', ['*'])->plainTextToken;
        $conversationId = (string) (new HostToolAgent($this->user))->continueLastConversation($this->user)->currentConversation();

        app('auth')->forgetGuards();

        $words = $this->withToken($token)->postJson('/api/assistant', ['messages' => [['id' => 'm2', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Never mind.']]]]])->assertOk();

        // The session again: the sanctum route made its guard the default.
        config(['auth.defaults.guard' => 'web']);
        app('auth')->forgetGuards();

        $this->withoutToken()->actingAs($this->user, 'web')->postJson('/assistant', ($this->body)([($this->answer)()]))
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('agentic-actions::stream.stale')]);

        expect(Parts::types(Parts::of($words->streamedContent())))->toContain('text-delta')
            ->and(Trace::$calls)->toBe([])
            ->and(app(ConversationStore::class)->pendingApprovalsFor($conversationId))->toBe([]);
    });
});
