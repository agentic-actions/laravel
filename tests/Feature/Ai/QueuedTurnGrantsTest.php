<?php

use AgenticActions\Ai\QueuedTurns;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Ai\QueuedTurnAgent;
use Tests\Fixtures\Queue\Queued;
use Tests\Fixtures\Streaming\OneStepGateway;
use Tests\Fixtures\Streaming\SaveNote;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A turn laravel/ai queues builds its tools in the worker, where no guard holds the caller's token. It keeps the grants
 * of the token that queued it, as a queued run does; a turn a session queued runs as the person.
 */

beforeEach(function () {
    $this->skipUnlessAi();

    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [SaveNote::class],
    ]);
    $this->refreshActions();
    Queued::onDatabase();

    $queue = function () {
        (new QueuedTurnAgent(request()->user()))->queue('Save a note called Queued.');

        return response()->noContent();
    };

    $this->mountRoutes(function () use ($queue) {
        Route::middleware(['api', 'auth:sanctum'])->post('/api/turns', $queue);
        Route::middleware(['web', 'auth'])->post('/turns', $queue);
    });

    (new OneStepGateway([new ToolCall('call_1', 'save-note', ['title' => 'Queued'])], 'Saved.'))->fake(QueuedTurnAgent::class);

    $this->user = User::factory()->create();
});

it('keeps the limits of the token that queued the turn', function (array $abilities, int $notes) {
    $token = $this->user->createToken('client', $abilities)->plainTextToken;

    $this->withToken($token)->postJson('/api/turns')->assertNoContent();

    expect(json_decode((string) DB::table('jobs')->value('payload'), true)[QueuedTurns::KEY] ?? null)->toBe($abilities);

    Auth::forgetGuards();
    Queued::work();

    expect(Post::query()->count())->toBe($notes);
})->with([
    'a token that may only read' => [['actions:read'], 0],
    'a token that may write' => [['actions:write'], 1],
]);

it('runs a turn a session queued as the person, with no grants kept', function () {
    $this->actingAs($this->user)->post('/turns')->assertNoContent();

    expect(json_decode((string) DB::table('jobs')->value('payload'), true))->not->toHaveKey(QueuedTurns::KEY);

    Auth::forgetGuards();
    Queued::work();

    expect(Post::query()->count())->toBe(1);
});
