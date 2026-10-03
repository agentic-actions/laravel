<?php

use AgenticActions\Streaming\Transcript;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Streaming\ErrorGateway;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\PagingStore;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Streaming\PlainStore;
use Tests\Fixtures\Streaming\StreamAgent;
use Tests\Fixtures\Streaming\ThrowingTool;
use Workbench\App\Models\User;

/*
 * Transcript gives a reloaded chat the words of a conversation the store says is this participant's: user and
 * assistant text, oldest first, without a failed reply, a tool call or a result.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Streaming'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();

    Auth::shouldUse('web');
    ThrowingTool::$validation = false;
    Exceptions::fake();

    $this->user = User::factory()->create();

    // One streamed turn of the user's last conversation with the agent, through the given gateway.
    $this->turn = function (string $words, OneStepGateway $gateway): void {
        $gateway->fake(StreamAgent::class);

        Parts::body((new StreamAgent($this->user))->continueLastConversation($this->user)->stream($words));
    };

    // A completed turn that saved a note, then one that failed in its tool after writing a few words.
    $this->twoTurns = function (): string {
        ($this->turn)('First words.', new OneStepGateway([new ToolCall('call_1', 'save-note', ['title' => 'CANARY-ARG'])], 'First reply.'));
        ($this->turn)('Second words.', new OneStepGateway([new ToolCall('call_2', 'ThrowingTool', [])], lead: 'Let me check.'));

        return (string) DB::table('agent_conversations')->value('id');
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

it('gives the words of both turns, oldest first, without the failed reply', function () {
    $id = ($this->twoTurns)();

    $transcript = Transcript::forUseChat($id, $this->user);

    expect(array_map(fn (array $message): array => [$message['role'], $message['parts']], $transcript))->toBe([
        ['user', [['type' => 'text', 'text' => 'First words.']]],
        ['assistant', [['type' => 'text', 'text' => 'First reply.']]],
        ['user', [['type' => 'text', 'text' => 'Second words.']]],
    ])->and(array_map(fn (array $message): array => array_keys($message), $transcript))->each->toBe(['id', 'role', 'parts'])
        ->and(DB::table('agent_conversation_messages')->where('status', 'failed')->value('content'))->toBe('Let me check.');
});

it('gives no tool call, result or argument', function () {
    $transcript = json_encode(Transcript::forUseChat(($this->twoTurns)(), $this->user), JSON_THROW_ON_ERROR);

    expect($transcript)->not->toContain('CANARY-ARG')
        ->not->toContain('save-note')
        ->not->toContain('ThrowingTool')
        ->not->toContain('Done.');
});

it('adds nothing for a turn that failed before its first step', function () {
    $id = ($this->twoTurns)();

    ($this->turn)('Third words.', new ErrorGateway);

    expect(Transcript::forUseChat($id, $this->user))->toHaveCount(3);
});

it('stores nothing, not even the conversation, when the first turn of a new conversation fails before its first step', function () {
    // A turn is stored once the model has finished a step; a provider error in the first one leaves nothing.
    foreach ([new ErrorGateway(started: false), new ErrorGateway] as $gateway) {
        $gateway->fake(StreamAgent::class);

        $body = Parts::body((new StreamAgent($this->user))->continueLastConversation($this->user)->stream('Lost words.'));

        expect(Parts::types(Parts::of($body)))->toContain('error')->not->toContain('finish');
    }

    expect(DB::table('agent_conversations')->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(0)
        ->and((new StreamAgent($this->user))->continueLastConversation($this->user)->currentConversation())->toBeNull();
});

it('keeps the newest messages within the limit', function () {
    ($this->turn)('First words.', new OneStepGateway(text: 'First reply.'));
    ($this->turn)('Second words.', new OneStepGateway(text: 'Second reply.'));

    $transcript = Transcript::forUseChat((string) DB::table('agent_conversations')->value('id'), $this->user, 2);

    expect(array_column(array_column(array_column($transcript, 'parts'), 0), 'text'))->toBe(['Second words.', 'Second reply.']);
});

it('keeps the newest messages whatever cursor the page\'s own request carries', function (string $parameter) {
    ($this->turn)('First words.', new OneStepGateway(text: 'First reply.'));
    ($this->turn)('Second words.', new OneStepGateway(text: 'Second reply.'));

    // The page lists something else, cursor-paginated: a cursor at the second newest message, or at another list's row.
    $second = DB::table('agent_conversation_messages')->orderByDesc('id')->skip(1)->value('id');
    $cursor = new Cursor([$parameter => $parameter === 'id' ? $second : '2026-09-01 00:00:00']);
    $this->app->instance('request', Request::create('/posts', 'GET', ['cursor' => $cursor->encode()]));

    $transcript = Transcript::forUseChat((string) DB::table('agent_conversations')->value('id'), $this->user, 2);

    expect(array_column(array_column(array_column($transcript, 'parts'), 0), 'text'))->toBe(['Second words.', 'Second reply.']);
})->with([
    'a cursor at an earlier message' => ['id'],
    'another list\'s cursor' => ['created_at'],
]);

it('gives nothing when the store says the conversation is another participant\'s', function () {
    $id = ($this->twoTurns)();

    expect(Transcript::forUseChat($id, User::factory()->create()))->toBe([])
        ->and(Transcript::forUseChat('01999999-0000-7000-8000-000000000000', $this->user))->toBe([]);
});

it('gives nothing from a store that cannot paginate', function () {
    $id = ($this->twoTurns)();

    $this->app->instance(ConversationStore::class, new PlainStore);

    expect(Transcript::forUseChat($id, $this->user))->toBe([]);
});

it('leaves ownership to the caller for a store that cannot verify it', function () {
    $id = ($this->twoTurns)();

    $this->app->instance(ConversationStore::class, new PagingStore);

    expect(Transcript::forUseChat($id, User::factory()->create()))->toHaveCount(3);
});
