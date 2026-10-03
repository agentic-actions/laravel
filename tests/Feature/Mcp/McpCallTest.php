<?php

use AgenticActions\Events\ActionCompleted;
use AgenticActions\Events\ActionFailed;
use AgenticActions\Surface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Mcp\Actions\McpTranslated;
use Tests\Fixtures\Mcp\ArabicUser;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * A tools/call runs the agent path through the MCP door, and answers with the sentence an agent reads: an isError
 * result for every refusal, never a throw.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    McpTranslated::$received = null;

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

/**
 * The one text content and the error flag of a tools/call result.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: string, 1: bool}
 */
function mcpCall(string $name, array $arguments = [], ?string $token = null): array
{
    $result = test()->mcp('/mcp/actions', JsonRpc::call($name, $arguments), $token ?? test()->token)->assertOk()->json('result');

    expect($result['content'])->toHaveCount(1)
        ->and($result['content'][0]['type'])->toBe('text');

    return [$result['content'][0]['text'], $result['isError']];
}

it('answers a Read with Found. and its output as data after ---', function () {
    Post::query()->forceCreate(['user_id' => $this->user->id, 'title' => 'Hi', 'body' => 'x', 'status' => 'draft']);

    [$text, $isError] = mcpCall('mcp-read-posts');

    expect($isError)->toBeFalse()
        ->and($text)->toBe("Found.\n---\n".json_encode(['posts' => [['id' => 1, 'title' => 'Hi']]]));
});

it('answers a Write with Done. and writes', function () {
    [$text, $isError] = mcpCall('mcp-create-post', ['title' => 'Hello', 'body' => 'x']);

    expect([$text, $isError])->toBe(['Done.', false])
        ->and(Post::query()->sole()->only(['user_id', 'title']))->toBe(['user_id' => $this->user->id, 'title' => 'Hello']);
});

it('answers invalid arguments with the rejected sentence as an error', function () {
    [$text, $isError] = mcpCall('mcp-create-post', ['title' => str_repeat('x', 30)]);

    expect($isError)->toBeTrue()
        ->and($text)->toStartWith('Not done. Rejected: ')
        ->and($text)->toContain('title', 'body')
        ->and(Post::query()->count())->toBe(0);
});

it('translates agentSchema() arguments into canonical input with fromAgent()', function () {
    [$text] = mcpCall('mcp-translated', ['headline' => 'Launch', 'title' => 'Sneaky']);

    expect($text)->toBe('Done.')
        ->and(McpTranslated::$received)->toBe(['title' => 'Launch', 'body' => 'From a model.']);
});

it('refuses an action that requires an idempotency key, which MCP never carries', function () {
    expect(mcpCall('mcp-keyed'))->toBe([trans('agentic-actions::model.idempotency_required'), true]);
});

it('answers a crash with the failed sentence, reports it once and fires ActionFailed', function () {
    Exceptions::fake();
    Event::fake([ActionFailed::class]);

    expect(mcpCall('mcp-crashes'))->toBe([trans('agentic-actions::model.failed'), true]);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The recount crashed.');
    Event::assertDispatchedTimes(ActionFailed::class, 1);
    Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event): bool => $event->surface === Surface::Mcp && $event->modelDriven);
});

it('never lets a throw reach laravel/mcp, even with app.debug on', function () {
    config(['app.debug' => true]);
    Exceptions::fake();

    // A listener runs after the Runner has settled the outcome, outside the action's own code.
    Event::listen(ActionCompleted::class, fn () => throw new LogicException('A listener broke.'));

    expect(mcpCall('mcp-create-post', ['title' => 'Hello', 'body' => 'x']))->toBe([trans('agentic-actions::model.failed'), true]);

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'A listener broke.');
});

it('speaks the person\'s stored language: sentences and instructions', function () {
    $person = ArabicUser::query()->create(['name' => 'Salma', 'email' => 'salma@example.test', 'password' => 'secret']);
    $token = $person->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    expect(app()->getLocale())->toBe('en')
        ->and(mcpCall('mcp-create-post', ['title' => 'مرحبا', 'body' => 'x'], $token))->toBe([trans('agentic-actions::model.done', [], 'ar'), false])
        ->and(mcpCall('mcp-keyed', [], $token))->toBe([trans('agentic-actions::model.idempotency_required', [], 'ar'), true]);

    $this->mcp('/mcp/actions', JsonRpc::legacy('initialize', ['protocolVersion' => '2025-11-25']), $token)
        ->assertJsonPath('result.instructions', trans('agentic-actions::mcp.instructions', [], 'ar'));

    expect(trans('agentic-actions::model.done', [], 'ar'))->not->toBe('Done.');
});
