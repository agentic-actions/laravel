<?php

use AgenticActions\Ai\ActionTool;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Approvals\ScriptedGateway;
use Tests\Fixtures\Deferred\ReportsAgent;
use Tests\Fixtures\Streaming\Parts;
use Tests\Fixtures\Streaming\SearchingGateway;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * #[DeferToolset]: the actions of an agent's deferred toolsets reach the model through one tool-search group, sent
 * before the actions it loads on every step, each action once, and they run as any action tool does.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'agentic-actions.discovery.paths' => [...config('agentic-actions.discovery.paths'), dirname(__DIR__, 2).'/Fixtures/Deferred'],
        'ai.conversations.generate_title' => false,
    ]);

    $this->refreshActions();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

/**
 * The names of the action tools among these tools, in order.
 *
 * @param  iterable<mixed>  $tools
 * @return list<string>
 */
function actionToolNames(iterable $tools): array
{
    $names = [];

    foreach ($tools as $tool) {
        if ($tool instanceof ActionTool) {
            $names[] = $tool->name();
        }
    }

    return $names;
}

it('sends the deferred toolsets as one tool-search group, first, then the loaded ones, with each action once', function () {
    if ($this->laravelAiBefore('1.1.0')) {
        $this->markTestSkipped('laravel/ai 1.0 receives deferred toolsets as ordinary tools.');
    }

    $tools = [...(new ReportsAgent($this->user))->tools()];

    expect($tools)->toHaveCount(2)
        ->and($tools[0])->toBeInstanceOf(ToolSearch::class)
        ->and(actionToolNames($tools[0]->tools))->toBe(['create-note', 'late-authorize', 'list-notes', 'team-note', 'translated-note'])
        ->and(actionToolNames([$tools[1]]))->toBe(['note-stats']);
});

it('sends no group to a person who gets none of the deferred actions', function () {
    $tools = [...(new ReportsAgent)->tools()];

    expect($tools)->toHaveCount(1)
        ->and(actionToolNames($tools))->toBe(['note-stats']);
});

it('runs a deferred action on a provider that searches tools, and on one that does not', function (string $provider) {
    (new ScriptedGateway([['create-note', ['title' => 'Found it', 'body' => 'x']]]))->install($provider);

    (new ReportsAgent($this->user))->prompt('Write a note called Found it.', provider: $provider);

    expect(Post::query()->where('user_id', $this->user->id)->pluck('title')->all())->toBe(['Found it']);
})->with([
    'a provider that searches tools' => 'openai',
    'a provider that does not' => 'ollama',
]);

it('streams the search a provider runs as nothing, and the action it found as its row', function () {
    (new SearchingGateway([new ToolCall('call_1', 'create-note', ['title' => 'Found it', 'body' => 'x'])], 'Saved it.'))->fake(ReportsAgent::class);

    $body = Parts::body((new ReportsAgent($this->user))->stream('Write a note called Found it.'));

    expect($body)->not->toContain('CANARY-SEARCH')
        ->and(Parts::rows(Parts::of($body)))->toBe([['action' => 'create-note', 'label' => 'Saved', 'status' => 'done', 'effect' => 'write', 'touches' => ['*']]])
        ->and(Post::query()->where('user_id', $this->user->id)->pluck('title')->all())->toBe(['Found it']);
});
