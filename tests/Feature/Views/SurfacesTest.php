<?php

use AgenticActions\ActionContext;
use AgenticActions\Facades\Actions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Views\Invalid\WriteWithTable;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A table action's output is one shape on every surface: the declared columns of at most views.max_rows rows. Where no
 * person sees a table, a model reads the whole output after today's Read sentence.
 */

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PostStats::class],
        'agentic-actions.views.max_rows' => 2,
        'agentic-actions.views.model_rows' => 1,
    ]);

    $this->refreshActions();
    Auth::shouldUse('web');

    $this->user = User::factory()->create();

    foreach (['Launch notes' => 'one two three', 'Roadmap' => 'one', 'Draft' => 'one two three four'] as $title => $body) {
        Post::factory()->for($this->user)->create(['title' => $title, 'body' => $body]);
    }

    // Two of the three rows, since views.max_rows is 2; the author, a record, reads as null in its text column.
    $this->table = [
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
    ];
});

it('gives the route, Actions::attempt() and an MCP client the same table, and Action::run() handle()\'s own value', function () {
    $this->mountRoutes(fn () => Route::middleware('web')->group(fn () => Actions::routes()));

    $route = $this->actingAs($this->user)->postJson('/actions/post-stats')->assertOk()->json();

    $attempt = Actions::attempt(PostStats::class, [], ActionContext::http($this->user))->output();
    $raw = PostStats::run([], ActionContext::http($this->user));

    $this->app['auth']->forgetGuards();
    $mcp = $this->postJson('/mcp/actions', JsonRpc::call('post-stats'), [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$this->user->createToken('client', ['actions:read'])->plainTextToken,
    ])->assertOk()->json('result.content.0.text');

    expect($route)->toBe($this->table)
        ->and($attempt)->toBe($this->table)
        ->and($mcp)->toBe("Found.\n---\n".json_encode($this->table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->and($raw)->toBeInstanceOf(Collection::class)
        ->and($raw->pluck('secret')->unique()->all())->toBe(['CANARY-SECRET']);
});

it('gives a prompt() turn, where no person sees a table, today\'s Read sentence with every row', function () {
    $this->skipUnlessAi();

    $heard = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$heard): void {
        $heard[] = (string) $event->result;
    });

    (new OneStepGateway([new ToolCall('call_1', 'post-stats', [])], 'Two posts.'))->fake(ViewsAgent::class);

    (new ViewsAgent($this->user))->prompt('How long are my posts?');

    expect($heard)->toBe(["Found.\n---\n".json_encode($this->table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
});

it('treats a ShowsTable action that is not a Read as an ordinary action', function () {
    expect(Actions::attempt(WriteWithTable::class, [], ActionContext::system())->output())->toBe(['saved' => true]);
});
