<?php

use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/*
 * Nothing but a literal, non-session grant reaches an action over MCP, and Destructive and External actions are
 * never listed or run.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

/**
 * The tool names a token lists on the base path.
 *
 * @return list<string>
 */
function mcpToolsFor(?string $token): array
{
    return test()->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $token)->assertOk()->json('result.tools.*.name');
}

it('answers a request without a credential with 401 and a Bearer challenge', function () {
    $this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'))
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');

    $this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), 'not-a-token')->assertUnauthorized();
});

it('lists the tools each literal ability reaches, and a Write it does not reach is not found', function (array $abilities, array $tools, string $path, string $answer) {
    $token = $this->user->createToken('client', $abilities)->plainTextToken;

    expect(mcpToolsFor($token))->toBe($tools);

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-create-post', ['title' => 'Hi', 'body' => 'x']), $token)->assertJsonPath($path, $answer);

    expect(Post::query()->count())->toBe($path === 'error.message' ? 0 : 1);
})->with([
    'read' => [['actions:read'], ['mcp-read-posts'], 'error.message', 'Tool [mcp-create-post] not found.'],
    'write' => [['actions:write'], ['mcp-crashes', 'mcp-create-post', 'mcp-keyed', 'mcp-translated'], 'result.content.0.text', 'Done.'],
    'read and write' => [['actions:read', 'actions:write'], ['mcp-crashes', 'mcp-create-post', 'mcp-keyed', 'mcp-read-posts', 'mcp-translated'], 'result.content.0.text', 'Done.'],
]);

it('lists nothing and runs nothing once surfaces.mcp is turned off after the routes are mounted', function () {
    $token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    config(['agentic-actions.surfaces.mcp' => false]);

    expect(mcpToolsFor($token))->toBe([]);

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-create-post', ['title' => 'Hi', 'body' => 'x']), $token)
        ->assertJsonPath('error.message', 'Tool [mcp-create-post] not found.');

    expect(Post::query()->count())->toBe(0);
});

it('never lists or runs a Destructive action, whatever the token grants', function () {
    $token = $this->user->createToken('all', ['actions:read', 'actions:write', 'actions:destructive', 'actions:external'])->plainTextToken;
    Post::query()->forceCreate(['user_id' => $this->user->id, 'title' => 'Keep', 'body' => 'x', 'status' => 'draft']);

    expect(mcpToolsFor($token))->not->toContain('mcp-delete-post');

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-delete-post', ['title' => 'Keep']), $token)
        ->assertJsonPath('error.message', 'Tool [mcp-delete-post] not found.');

    expect(Post::query()->pluck('title')->all())->toBe(['Keep']);
});
