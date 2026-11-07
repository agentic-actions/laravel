<?php

use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\User;

/*
 * A member of the wrong JSON type is the client's mistake: invalid params, which nobody's error tracker hears about.
 */

uses(McpEnvironment::class);

it('answers a member of the wrong type with invalid params, and reports nothing', function (string $method, array $params, string $member, string $type) {
    $this->bootMcp();
    Exceptions::fake();

    $token = User::factory()->create()->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    $this->mcp('/mcp/actions', JsonRpc::legacy($method, $params), $token)
        ->assertStatus(400)
        ->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => "Invalid params: The [{$member}] member must be {$type}."]]);

    Exceptions::assertNothingReported();
})->with([
    'a list as a tool name' => ['tools/call', ['name' => ['mcp-read-posts'], 'arguments' => (object) []], 'name', 'a string'],
    'an object as a tool name' => ['tools/call', ['name' => ['a' => 1], 'arguments' => (object) []], 'name', 'a string'],
    'a number as a tool name' => ['tools/call', ['name' => 7, 'arguments' => (object) []], 'name', 'a string'],
    'a list as a prompt name' => ['prompts/get', ['name' => ['a']], 'name', 'a string'],
    'a list as a resource uri' => ['resources/read', ['uri' => ['a']], 'uri', 'a string'],
    'a list as a cursor' => ['tools/list', ['cursor' => ['a']], 'cursor', 'a string'],
    'a number as a cursor' => ['tools/list', ['cursor' => 7], 'cursor', 'a string'],
    'a list as a page size' => ['tools/list', ['per_page' => ['a']], 'per_page', 'an integer'],
]);

it('still answers the same calls with members of the right type', function () {
    $this->bootMcp();

    $token = User::factory()->create()->createToken('client', ['actions:read'])->plainTextToken;

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-read-posts'), $token)->assertOk()->assertJsonPath('result.isError', false);
    $this->mcp('/mcp/actions', JsonRpc::legacy('tools/list', ['per_page' => 1]), $token)->assertOk()->assertJsonCount(1, 'result.tools');
    $this->mcp('/mcp/actions', JsonRpc::legacy('tools/call', ['arguments' => (object) []]), $token)->assertJsonPath('error.message', 'Missing [name] parameter.');
});
