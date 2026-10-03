<?php

use Laravel\Mcp\Enums\MetaKey;
use Tests\Fixtures\Elicitation\McpAskingDraft;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Over MCP an asking action's incomplete call is refused naming the fields, as before 0.5, for every client that does
 * not declare form elicitation on a 2026-07-28 request: no form, no input_required result, and never -32021. The
 * clients that do get a form (tests/Feature/Mcp/McpElicitationTest.php). It needs no laravel/ai.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [McpAskingDraft::class]]);

    McpAskingDraft::$handled = null;

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

it('refuses a 2026-07-28 call whose client does not declare form elicitation, naming the fields', function (array|stdClass $capabilities) {
    [$body, $headers] = JsonRpc::current('tools/call', ['name' => 'mcp-asking-draft', 'arguments' => ['title' => 'Launch notes']]);
    $body['params']['_meta'][MetaKey::CLIENT_CAPABILITIES->value] = $capabilities;

    $response = $this->mcp('/mcp/actions', $body, $this->token, $headers)->assertOk();

    expect($response->json('result.resultType'))->toBe('complete')
        ->and($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe('Not done. Rejected: body (required), status (required).')
        ->and($response->json('result'))->not->toHaveKeys(['inputRequests', 'requestState'])
        ->and($response->json('error'))->toBeNull()
        ->and(McpAskingDraft::$handled)->toBeNull()
        ->and(Post::query()->count())->toBe(0);
})->with([
    'no elicitation' => [(object) []],
    'elicitation with url only' => [['elicitation' => ['url' => (object) []]]],
    'another capability' => [['sampling' => (object) []]],
]);

it('refuses a legacy call the same way, whatever it declared at initialization', function () {
    $initialize = JsonRpc::legacy('initialize', [
        'protocolVersion' => '2025-11-25',
        'capabilities' => ['elicitation' => ['form' => (object) []]],
        'clientInfo' => ['name' => 'probe', 'version' => '1.0'],
    ]);
    $this->mcp('/mcp/actions', $initialize, $this->token)->assertOk();

    $response = $this->mcp('/mcp/actions', JsonRpc::call('mcp-asking-draft', ['title' => 'Launch notes']), $this->token)->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe('Not done. Rejected: body (required), status (required).')
        ->and($response->json('result'))->not->toHaveKeys(['inputRequests', 'requestState']);
});

it('runs a complete call as before', function () {
    [$body, $headers] = JsonRpc::current('tools/call', ['name' => 'mcp-asking-draft', 'arguments' => ['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']]);
    $body['params']['_meta'][MetaKey::CLIENT_CAPABILITIES->value] = ['elicitation' => ['form' => (object) []]];

    $this->mcp('/mcp/actions', $body, $this->token, $headers)
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Done.');

    expect(McpAskingDraft::$handled)->toBe(['title' => 'Launch notes', 'body' => 'x', 'status' => 'draft']);
});
