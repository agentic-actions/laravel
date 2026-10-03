<?php

use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Outcome;
use AgenticActions\Streaming\AgenticView;
use AgenticActions\Streaming\Transcript;
use AgenticActions\Views\Rows;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Views\PostsByStatus;
use Tests\Fixtures\Views\PostStats;
use Tests\Fixtures\Views\ViewsAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * Each table shown in a stored conversation is kept once, by its tool-call id, with the input and fixed keys a refresh
 * runs again, as long as its conversation is; a reload shows it again inside its message while the agent still offers
 * its action.
 */

/**
 * The data-view parts among these parts, in order.
 *
 * @param  list<array<string, mixed>|string>  $parts
 * @return list<array<string, mixed>>
 */
function viewParts(array $parts): array
{
    return array_values(array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-view'));
}

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [PostStats::class, PostsByStatus::class],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();
    Auth::shouldUse('web');
    PostsByStatus::reset();

    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->create(['title' => 'Launch notes', 'status' => 'published']);

    // One streamed turn of the agent, with the tool calls in one provider step, then a reply.
    $this->turn = function (ViewsAgent $agent, array $toolCalls): array {
        (new OneStepGateway($toolCalls, 'Replied.'))->fake(ViewsAgent::class);

        return Parts::of(Parts::body($agent->stream('Show me.')));
    };
});

afterEach(function () {
    ignore_user_abort(false);
});

it('keeps each table shown, by its tool-call id, from the first turn of a new conversation on', function () {
    ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [
        new ToolCall('call_1', 'post-stats', []),
        new ToolCall('call_2', 'posts-by-status', ['status' => 'published']),
    ]);

    ($this->turn)((new ViewsAgent($this->user))->continueLastConversation($this->user), [new ToolCall('call_3', 'post-stats', [])]);

    $conversation = DB::table('agent_conversations')->sole()->id;

    expect(AgenticView::query()->orderBy('id')->get()->map(fn (AgenticView $view): array => [
        $view->conversation_id, $view->tool_call_id, $view->action, $view->participant_type, (int) $view->participant_id, $view->tenant_type, $view->tenant_id, $view->table['rows'][0]['title'],
    ])->all())->toBe([
        [$conversation, 'call_1', 'post-stats', $this->user->getMorphClass(), $this->user->id, null, null, 'Launch notes'],
        [$conversation, 'call_2', 'posts-by-status', $this->user->getMorphClass(), $this->user->id, null, null, 'Launch notes'],
        [$conversation, 'call_3', 'post-stats', $this->user->getMorphClass(), $this->user->id, null, null, 'Launch notes'],
    ]);
});

it('keeps the input as it entered prepareForValidation() and the fixed keys, and never serializes them or the table', function () {
    ($this->turn)((new ViewsAgent($this->user, fixed: ['view' => 'mine']))->forUser($this->user), [
        new ToolCall('call_1', 'posts-by-status', ['status' => 'Published']),
    ]);

    $view = AgenticView::query()->sole();

    expect([$view->input, $view->fixed])->toBe([['status' => 'Published', 'view' => 'mine'], ['view' => 'mine']])
        ->and(PostsByStatus::$runs[0]['input'])->toBe(['status' => 'published', 'view' => 'mine'])
        ->and($view->toArray())->not->toHaveKeys(['input', 'fixed', 'table']);
});

it('keeps neither table when a tool-call id comes again in one conversation, and still shows each', function () {
    $first = ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'post-stats', [])]);
    $again = ($this->turn)((new ViewsAgent($this->user))->continueLastConversation($this->user), [new ToolCall('call_1', 'post-stats', [])]);

    expect(AgenticView::query()->count())->toBe(0)
        ->and(viewParts($first)[0]['data'])->toHaveKey('ref')
        ->and(viewParts($again)[0]['data'])->not->toHaveKey('ref');
});

it('reports a missing table at most once an hour, and still shows the table', function () {
    Exceptions::fake();
    Schema::connection((new AgenticView)->getConnectionName())->drop('agentic_views');

    foreach (['call_1', 'call_2'] as $call) {
        $parts = ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall($call, 'post-stats', [])]);

        expect(Parts::types($parts))->toContain('data-view');
    }

    Exceptions::assertReportedCount(1);
});

it('prunes the tables of a conversation that is gone, and only those, once they are a day old', function () {
    foreach (['call_1', 'call_2'] as $call) {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall($call, 'post-stats', [])]);
    }

    $gone = AgenticView::query()->where('tool_call_id', 'call_1')->value('conversation_id');
    DB::table('agent_conversations')->where('id', $gone)->delete();

    // A new conversation's row is stored when its first turn ends, so a table that turn showed waits a day.
    $this->artisan('model:prune', ['--model' => AgenticView::class])->assertSuccessful();

    expect(AgenticView::query()->orderBy('tool_call_id')->pluck('tool_call_id')->all())->toBe(['call_1', 'call_2']);

    $this->travel(25)->hours();
    $this->artisan('model:prune', ['--model' => AgenticView::class])->assertSuccessful();

    expect(AgenticView::query()->pluck('tool_call_id')->all())->toBe(['call_2']);
});

describe('record()', function () {
    it('keeps a result\'s own input over the call\'s, such as a dataset\'s resolved dates', function () {
        $rows = new Rows([], ['title'], input: ['since' => '2026-09-01']);
        $outcome = Outcome::completed(ClassExposure::of(PostStats::class), ActionContext::agent($this->user), $rows, ['columns' => []], null, ['since' => '-30d', 'until' => 'today']);

        expect(AgenticView::record($outcome, 'conversation-1', 'call_1')?->input)->toBe(['since' => '2026-09-01', 'until' => 'today']);
    });

    it('keeps nothing without an actor', function () {
        $outcome = Outcome::completed(ClassExposure::of(PostStats::class), ActionContext::agent(null), [], ['columns' => []], null);

        expect(AgenticView::record($outcome, 'conversation-1', 'call_1'))->toBeNull()
            ->and(AgenticView::query()->count())->toBe(0);
    });
});

describe('the reload', function () {
    beforeEach(function () {
        // The page's reload of the author's last conversation, with the agent as it stands now.
        $this->reload = fn (?ViewsAgent $agent = null): array => Transcript::forUseChat(
            (string) DB::table('agent_conversations')->orderByDesc('id')->value('id'),
            $this->user,
            agent: ($agent ?? new ViewsAgent($this->user))->continueLastConversation($this->user),
        );
    });

    it('gives each table back inside its assistant message, before the words, as the stream sent it', function () {
        $parts = ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [
            new ToolCall('call_1', 'post-stats', []),
            new ToolCall('call_2', 'posts-by-status', ['status' => 'published']),
        ]);

        $reloaded = ($this->reload)();

        expect(array_column($reloaded, 'role'))->toBe(['user', 'assistant'])
            ->and($reloaded[1]['parts'])->toBe([...viewParts($parts), ['type' => 'text', 'text' => 'Replied.']]);
    });

    it('gives a table back only while the agent\'s tools still offer its action', function () {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [
            new ToolCall('call_1', 'post-stats', []),
            new ToolCall('call_2', 'posts-by-status', ['status' => 'published']),
        ]);

        config(['agentic-actions.discovery.classes' => [PostsByStatus::class]]);
        $this->refreshActions();

        expect(array_column(viewParts(($this->reload)()[1]['parts']), 'id'))->toBe(['view:call_2']);
    });

    it('re-projects a table onto the columns its action declares now', function () {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'posts-by-status', ['status' => 'published'])]);

        PostsByStatus::$titleOnly = true;

        $table = viewParts(($this->reload)()[1]['parts'])[0]['data']['table'];

        expect([$table['columns'], $table['rows']])->toBe([[['key' => 'title', 'label' => 'Title', 'type' => 'text']], [['title' => 'Launch notes']]]);
    });

    it('never gives back another participant\'s table, or another conversation\'s under the same call id', function () {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'post-stats', [])]);
        $earlier = AgenticView::query()->sole();

        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'post-stats', []), new ToolCall('call_2', 'post-stats', [])]);
        AgenticView::query()->where('tool_call_id', 'call_2')->update(['participant_id' => User::factory()->create()->id]);

        $refs = array_column(array_column(viewParts(($this->reload)()[1]['parts']), 'data'), 'ref');

        expect($refs)->toBe([AgenticView::query()->where('tool_call_id', 'call_1')->whereKeyNot($earlier->id)->sole()->id]);
    });

    it('gives no table back through an agent that acts for another person, even in this conversation', function () {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'post-stats', [])]);
        $conversation = (string) DB::table('agent_conversations')->orderByDesc('id')->value('id');

        $stranger = (new ViewsAgent(User::factory()->create()))->continue($conversation, as: $this->user);

        expect(viewParts(Transcript::forUseChat($conversation, $this->user, agent: $stranger)[1]['parts']))->toBe([])
            ->and(viewParts(($this->reload)()[1]['parts']))->toHaveCount(1);
    });

    it('gives back the newest twenty tables of the page at most', function () {
        $agent = (new ViewsAgent($this->user))->forUser($this->user);

        foreach ([1, 2, 3] as $turn) {
            ($this->turn)($agent, array_map(fn (int $n): ToolCall => new ToolCall("call_{$turn}_{$n}", 'post-stats', []), range(1, 7)));
            $agent = (new ViewsAgent($this->user))->continueLastConversation($this->user);
        }

        $ids = array_column(viewParts(array_merge(...array_column(($this->reload)(), 'parts'))), 'id');

        expect($ids)->toHaveCount(20)
            ->and($ids[0])->toBe('view:call_1_2')
            ->and(end($ids))->toBe('view:call_3_7');
    });

    it('gives a table back only in the tenant it was shown in', function () {
        $this->useTeamTenancy();
        [$acme, $other] = [Team::factory()->create(['slug' => 'acme']), Team::factory()->create(['slug' => 'other'])];
        $this->user->teams()->attach([$acme->id, $other->id]);

        ($this->turn)((new ViewsAgent($this->user, $acme))->forUser($this->user), [new ToolCall('call_1', 'post-stats', [])]);

        expect(viewParts(($this->reload)(new ViewsAgent($this->user, $other))[1]['parts']))->toBe([])
            ->and(viewParts(($this->reload)(new ViewsAgent($this->user, $acme))[1]['parts']))->toHaveCount(1);
    });

    it('reports a missing table at most once an hour, and still gives the words', function () {
        ($this->turn)((new ViewsAgent($this->user))->forUser($this->user), [new ToolCall('call_1', 'post-stats', [])]);

        Exceptions::fake();
        Schema::connection((new AgenticView)->getConnectionName())->drop('agentic_views');

        expect(($this->reload)()[1]['parts'])->toBe([['type' => 'text', 'text' => 'Replied.']])
            ->and(($this->reload)()[1]['parts'])->toBe([['type' => 'text', 'text' => 'Replied.']]);

        Exceptions::assertReportedCount(1);
    });
});
