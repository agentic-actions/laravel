<?php

use AgenticActions\ActionContext;
use AgenticActions\Events\ActionCompleted;
use AgenticActions\Facades\Actions;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Ai\NoContextAgent;
use Tests\Fixtures\Ai\NotesAgent;
use Tests\Fixtures\Ai\NoToolsetAgent;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * An agent receives its toolsets' actions as tools, built per turn from its own state, and laravel/ai's own fake
 * gateway drives the calls.
 */

beforeEach(function () {
    if (! interface_exists(Tool::class)) {
        return;
    }

    config(['agentic-actions.discovery.paths' => [
        ...config('agentic-actions.discovery.paths'),
        dirname(__DIR__, 2).'/Fixtures/Ai',
    ]]);

    $this->refreshActions();

    Auth::shouldUse('web');

    $this->user = User::factory()->create();
});

it('runs a tool call through the pipeline as the Agent surface', function () {
    $this->skipUnlessAi();

    Event::fake([ActionCompleted::class]);
    NotesAgent::fake([new ToolCall('call_1', 'create-note', ['title' => 'Hi', 'body' => 'x']), 'Done.']);

    $response = (new NotesAgent($this->user))->prompt('Write a note called Hi.');

    expect($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('Done.')
        ->and($response->text)->toBe('Done.')
        ->and(Post::query()->where('user_id', $this->user->id)->pluck('title')->all())->toBe(['Hi']);

    Event::assertDispatched(ActionCompleted::class, fn (ActionCompleted $event): bool => $event->action === 'create-note'
        && $event->surface === Surface::Agent
        && $event->modelDriven === true);
});

it('builds the tools from the agent\'s own state, not from the request', function () {
    $this->skipUnlessAi();

    $signedIn = User::factory()->create();
    $this->actingAs($signedIn);
    $this->app->forgetInstance('request');

    NotesAgent::fake([new ToolCall('call_1', 'create-note', ['title' => 'Mine', 'body' => 'x']), 'Done.']);

    (new NotesAgent($this->user))->prompt('Write a note called Mine.');

    expect(Post::query()->sole()->user_id)->toBe($this->user->id)
        ->and($this->app->resolved('request'))->toBeFalse();
});

it('names #[UseToolset] when an agent has none', function () {
    $this->skipUnlessAi();

    expect(fn () => (new NoToolsetAgent($this->user))->tools())
        ->toThrow(LogicException::class, NoToolsetAgent::class.' has no #[UseToolset]: name the toolsets it receives.');
});

it('names actionContext() when an agent does not override it', function () {
    $this->skipUnlessAi();

    expect(fn () => (new NoContextAgent)->tools())
        ->toThrow(LogicException::class, NoContextAgent::class.' must override actionContext()');
});

it('refuses to build tools when laravel/ai is not installed', function () {
    if (interface_exists(Tool::class)) {
        $this->markTestSkipped('laravel/ai is installed; this case runs in the "no laravel/ai" cell.');
    }

    expect(fn () => Actions::tools(ActionContext::agent(User::factory()->create()), ['default']))
        ->toThrow(LogicException::class, 'laravel/ai is not installed: composer require laravel/ai.');
});
