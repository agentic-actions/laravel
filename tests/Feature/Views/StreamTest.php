<?php

use AgenticActions\Streaming\ActionsProtocol;
use AgenticActions\Streaming\ActivityRecord;
use AgenticActions\Streaming\AgenticView;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Storage\StoredMessage;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Views\PostsByStatus;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A streamed turn that calls a table action: the person sees the rows as a data-view part right after the call's row
 * closes as done, and the model reads a compact copy, which laravel/ai stores as the tool's result.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PostStats::class, PostsByStatus::class],
        'agentic-actions.views.max_rows' => 2,
        'agentic-actions.views.model_rows' => 1,
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    $this->freezeTime();
    Auth::shouldUse('web');
    PostsByStatus::reset();

    $this->user = User::factory()->create();

    foreach (['Launch notes' => 'one two three', 'Roadmap' => 'one', 'Draft' => 'one two three four'] as $title => $body) {
        Post::factory()->for($this->user)->create(['title' => $title, 'body' => $body]);
    }

    // Stream one turn of a new conversation, the tool calls in one provider step, then a reply.
    $this->turn = function (array $toolCalls): array {
        (new OneStepGateway($toolCalls, 'Replied.'))->fake(ViewsAgent::class);

        return Parts::of(Parts::body((new ViewsAgent($this->user))->forUser($this->user)->stream('How long are my posts?')));
    };

    // What the model heard from each tool, in order.
    $this->heard = [];

    Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
        $this->heard[] = (string) $event->result;
    });
});

afterEach(function () {
    ignore_user_abort(false);
});

it('shows the declared columns of at most views.max_rows rows right after the call\'s row closes as done', function () {
    $parts = ($this->turn)([new ToolCall('call_1', 'post-stats', [])]);

    $view = array_search('data-view', Parts::types($parts), true);

    expect($parts[$view - 1]['data'])->toBe(['action' => 'post-stats', 'label' => 'Looked it up', 'status' => 'done', 'effect' => 'read'])
        ->and($parts[$view])->toBe(['type' => 'data-view', 'id' => 'view:call_1', 'data' => [
            'action' => 'post-stats',
            'table' => [
                'columns' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'words', 'label' => 'Words', 'type' => 'integer'],
                    ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                    ['key' => 'author', 'label' => 'Author', 'type' => 'text'],
                ],
                'rows' => [
                    ['title' => 'Launch notes', 'words' => 3, 'share' => 0.375, 'author' => null],
                    ['title' => 'Roadmap', 'words' => 1, 'share' => 0.125, 'author' => null],
                ],
                'truncated' => true,
                'chart' => ['type' => 'none', 'y' => []],
                'caption' => null,
            ],
            'at' => now()->format(DATE_ATOM),
            'ref' => AgenticView::query()->sole()->id,
            'labels' => ['refresh' => 'Refresh', 'truncated' => 'Showing the first :count rows'],
        ]])
        ->and(Parts::types($parts))->toContain('text-delta');
});

it('gives the model the compact copy, which laravel/ai stores as the tool\'s result', function () {
    ($this->turn)([new ToolCall('call_1', 'post-stats', [])]);

    $copy = "The person now sees this as a table of 2 rows.\nIt holds only the first 2 rows.\nSay in a sentence or two what stands out; do not repeat the rows.\n---\n"
        .json_encode(['columns' => [
            ['key' => 'title', 'label' => 'Title'], ['key' => 'words', 'label' => 'Words'], ['key' => 'share', 'label' => 'Share'], ['key' => 'author', 'label' => 'Author'],
        ], 'rows' => [['title' => 'Launch notes', 'words' => 3, 'share' => 0.375, 'author' => null]], 'truncated' => true]);

    $stored = StoredMessage::fromArray((array) DB::table('agent_conversation_messages')->where('role', 'assistant')->sole());

    expect($this->heard)->toBe([$copy])
        ->and(array_column($stored->toolResults(), 'result'))->toBe([$copy]);
});

it('keeps an undeclared key out of the part, the model\'s copy and the snapshot', function () {
    $body = json_encode(($this->turn)([new ToolCall('call_1', 'post-stats', [])]), JSON_THROW_ON_ERROR);

    expect($body)->not->toContain('CANARY-SECRET')
        ->and($this->heard[0])->not->toContain('CANARY-SECRET')
        ->and(json_encode(AgenticView::query()->sole()->table, JSON_THROW_ON_ERROR))->not->toContain('CANARY-SECRET');
});

it('shows no table for a call that was refused or failed', function (Closure $refuse, array $arguments, string $status) {
    Exceptions::fake();
    $refuse();

    $parts = ($this->turn)([new ToolCall('call_1', 'posts-by-status', $arguments)]);

    expect(Parts::rows($parts)[0]['status'])->toBe($status)
        ->and(Parts::types($parts))->not->toContain('data-view')
        ->and(AgenticView::query()->count())->toBe(0);
})->with([
    'invalid' => [fn () => null, [], 'refused'],
    'crashed' => [fn () => PostsByStatus::$crash = true, ['status' => 'draft'], 'failed'],
]);

it('runs a table\'s call with no confirmation ticket, though its tool holds the agent', function () {
    ($this->turn)([new ToolCall('call_1', 'posts-by-status', ['status' => 'published'])]);

    expect(array_column(PostsByStatus::$runs, 'ticket'))->toBe([false]);
});

it('shows at most ten tables in a turn; the eleventh call reads today\'s Read sentence', function () {
    $calls = array_map(fn (int $n): ToolCall => new ToolCall("call_{$n}", 'post-stats', []), range(1, 11));

    $parts = ($this->turn)($calls);

    $views = array_values(array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-view'));

    expect(array_column($views, 'id'))->toBe(array_map(fn (int $n): string => "view:call_{$n}", range(1, 10)))
        ->and(array_column(Parts::rows($parts), 'status'))->toBe(array_fill(0, 11, 'done'))
        ->and(AgenticView::query()->orderBy('id')->pluck('tool_call_id')->all())->toBe(array_map(fn (int $n): string => "call_{$n}", range(1, 10)))
        ->and($this->heard[9])->toStartWith('The person now sees this as a table of 2 rows.')
        ->and($this->heard[10])->toStartWith("Found.\n---\n");
});

it('shows one table per tool-call id in a turn: a provider that repeats an id gets the first table only', function () {
    $parts = ($this->turn)([new ToolCall('call_1', 'post-stats', []), new ToolCall('call_1', 'posts-by-status', ['status' => 'published'])]);

    $views = array_values(array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-view'));

    expect($views)->toHaveCount(1)
        ->and($views[0]['data']['action'])->toBe('post-stats');
});

it('closes a row still open at the stream\'s end without its table', function () {
    $protocol = new ActionsProtocol;

    $response = new StreamableAgentResponse('run-1', function () use ($protocol): Generator {
        $protocol->start('inv_1', 'post-stats', 'Looking it up…', 'Looked it up', null);
        $protocol->record('inv_1', fn (): ActivityRecord => new ActivityRecord('done', view: ['type' => 'data-view', 'id' => 'view:call_1', 'data' => []]));

        yield from [];
    }, new Meta);

    $parts = iterator_to_array((fn (): Generator => $this->parts($response))->call($protocol), false);

    expect(array_column($parts, 'type'))->toContain('data-action')
        ->not->toContain('data-view');
});

it('keeps data-view for itself: a host part of that type is dropped and reported', function () {
    Exceptions::fake();

    (new OneStepGateway)->fake(ViewsAgent::class);

    $parts = Parts::of(Parts::body((new ViewsAgent($this->user))->stream('Go.'), new ActionsProtocol(fn (): array => [
        ['type' => 'data-view', 'id' => 'view:forged', 'data' => ['action' => 'post-stats']],
    ])));

    expect(Parts::types($parts))->not->toContain('data-view');

    Exceptions::assertReported(fn (LogicException $exception): bool => str_starts_with($exception->getMessage(), 'A host part must be a data-* part other than'));
});
