<?php

use AgenticActions\Streaming\AgenticView;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\Parts;
use Workbench\App\Ai\BlogAssistant;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The copilot's tables on the workbench, through the real kernel: BlogAssistant is offered PostStats and the Posts
 * dataset. A turn through POST /assistant shows each as a data-view part after its row, the reload through
 * GET /assistant gives them back before the words, and the page's Refresh, through the _views route of the group the
 * workbench mounts, answers the author alone.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config(['ai.conversations.generate_title' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));

    $this->user = User::factory()->create();
    Post::factory()->for($this->user)->forTeam(Team::factory()->create(['name' => 'Blue']))->create(['title' => 'Launch notes', 'body' => 'one two three', 'status' => 'published', 'created_at' => '2026-09-10 10:00:00']);
    Post::factory()->for($this->user)->create(['title' => 'Roadmap', 'body' => 'one', 'status' => 'draft', 'created_at' => '2026-09-12 10:00:00']);

    // One turn through the workbench's endpoint, in which the model asks for both tables.
    (new OneStepGateway([
        new ToolCall('call_1', 'post-stats', []),
        new ToolCall('call_2', 'posts', ['measures' => ['posts', 'published'], 'by' => ['team'], 'since' => '-1m']),
    ], 'Launch notes is your longest post.'))->fake(BlogAssistant::class);

    $this->parts = Parts::of($this->actingAs($this->user)->json('POST', '/assistant', [
        'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'How are my posts doing?']]]],
    ], ['Accept' => 'application/json, text/event-stream'])->assertOk()->streamedContent());

    $this->tables = array_values(array_filter($this->parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-view'));
});

afterEach(function () {
    ignore_user_abort(false);
});

it('shows each table right after its row closes, with the columns it declares and a dataset\'s caption', function () {
    foreach ($this->tables as $index => $table) {
        $row = $this->parts[array_search($table, $this->parts, true) - 1];

        expect($row['data'])->toMatchArray(['action' => ['post-stats', 'posts'][$index], 'status' => 'done']);
    }

    expect(array_column($this->tables, 'id'))->toBe(['view:call_1', 'view:call_2'])
        ->and($this->tables[0]['data']['table'])->toMatchArray([
            'rows' => [['title' => 'Launch notes', 'words' => 3, 'share' => 0.75], ['title' => 'Roadmap', 'words' => 1, 'share' => 0.25]],
            'chart' => ['type' => 'bar', 'x' => 'title', 'y' => ['words']],
            'caption' => null,
        ])
        ->and($this->tables[1]['data']['table'])->toMatchArray([
            'rows' => [['team' => 'Blue', 'posts' => 1, 'published' => 1], ['team' => null, 'posts' => 1, 'published' => 0]],
            'chart' => ['type' => 'bar', 'x' => 'team', 'y' => ['posts', 'published']],
            'caption' => 'Posts, Published by Team · 1 Sep 2026 – 30 Sep 2026 · the 50 highest by Posts',
        ])
        ->and(AgenticView::query()->pluck('id')->sort()->values()->all())->toBe(collect(array_column(array_column($this->tables, 'data'), 'ref'))->sort()->values()->all());
});

it('gives both tables back on the reload, before the words, as the stream sent them', function () {
    $messages = $this->actingAs($this->user)->getJson('/assistant')->assertOk()->json('messages');

    expect(array_column($messages, 'role'))->toBe(['user', 'assistant'])
        ->and($messages[1]['parts'])->toBe([...$this->tables, ['type' => 'text', 'text' => 'Launch notes is your longest post.']]);
});

it('refreshes a table for its author, over the days it was asked for, and for no one else', function () {
    Post::factory()->for($this->user)->create(['title' => 'Later', 'body' => 'one', 'created_at' => '2026-09-20 10:00:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $ref = $this->tables[1]['data']['ref'];

    $this->actingAs($this->user)->postJson("/actions/_views/{$ref}")
        ->assertOk()
        ->assertJsonPath('table.rows', [['team' => null, 'posts' => 2, 'published' => 0], ['team' => 'Blue', 'posts' => 1, 'published' => 1]])
        ->assertJsonPath('table.caption', 'Posts, Published by Team · 1 Sep 2026 – 30 Sep 2026 · the 50 highest by Posts');

    $this->actingAs(User::factory()->create())->postJson("/actions/_views/{$ref}")->assertNotFound();
});
