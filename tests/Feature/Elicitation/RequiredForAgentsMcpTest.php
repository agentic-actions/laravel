<?php

use Tests\Fixtures\Elicitation\AskingRequired;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\User;

/*
 * requiredForAgents() over MCP: the tool's input schema requires the named fields, and a call without one is refused
 * naming it. It needs no laravel/ai.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [AskingRequired::class]]);

    AskingRequired::$handled = null;

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

it('lists the named fields as required', function () {
    $tools = collect($this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $this->token)->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools['asking-required']['inputSchema']['required'])->toBe(['title', 'publish_on', 'status']);
});

it('refuses a call that leaves them out, naming them, and runs a complete one', function () {
    $this->mcp('/mcp/actions', JsonRpc::call('asking-required', ['title' => 'Launch notes']), $this->token)
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'Not done. Rejected: publish_on (required), status (required).');

    expect(AskingRequired::$handled)->toBeNull();

    $this->mcp('/mcp/actions', JsonRpc::call('asking-required', ['title' => 'Launch notes', 'publish_on' => '2026-10-01', 'status' => 'draft']), $this->token)
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Done.');

    expect(AskingRequired::$handled)->toBe(['title' => 'Launch notes', 'publish_on' => '2026-10-01', 'status' => 'draft']);
});
