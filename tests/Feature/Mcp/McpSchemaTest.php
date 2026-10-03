<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Support\PackageStatus;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Mcp\Extra\McpNoDescription;
use Tests\Fixtures\Mcp\Extra\McpSecret;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\User;

/*
 * MCP tools advertise exactly what agents are offered, and their hints follow the live effect.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

afterEach(function () {
    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

/**
 * The listed tools on the base path, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function mcpListed(string $token): array
{
    return collect(test()->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $token)->assertOk()->json('result.tools'))
        ->keyBy('name')
        ->all();
}

it('advertises the input schema agents are offered: schema(), or agentSchema() in its place', function () {
    $tools = mcpListed($this->token);

    expect($tools)->toHaveCount(5)
        ->and($tools['mcp-create-post']['inputSchema'])->toEqual([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 20],
                'body' => ['type' => 'string'],
            ],
            'required' => ['title', 'body'],
        ])
        ->and($tools['mcp-translated']['inputSchema'])->toEqual([
            'type' => 'object',
            'properties' => ['headline' => ['type' => 'string', 'maxLength' => 40]],
            'required' => ['headline'],
        ]);
});

it('never lists an action with a forbidden input key, and reports it in production', function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [McpSecret::class]]);
    Exceptions::fake();

    $user = User::factory()->create();
    $token = $user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
    $this->app['env'] = 'production';

    expect(app(ActionRegistry::class)->find('mcp-secret'))->not->toBeNull()
        ->and(mcpListed($token))->not->toHaveKey('mcp-secret');

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-secret', ['password' => 'x']), $token)
        ->assertJsonPath('error.message', 'Tool [mcp-secret] not found.');

    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), '[password]'));
});

it('never lists a bare #[Expose] action without a description', function () {
    config(['agentic-actions.discovery.classes' => [McpNoDescription::class]]);
    $this->usePackages(['laravel/ai' => PackageStatus::Missing]);

    expect(app(ActionRegistry::class)->find('mcp-no-description')?->skipped)->toBe(['agent' => 'laravel/ai is not installed', 'mcp' => 'no description'])
        ->and(mcpListed($this->token))->not->toHaveKey('mcp-no-description');
});

it('sends no output schema, and hints from the live effect', function () {
    $tools = mcpListed($this->token);

    foreach ($tools as $tool) {
        expect($tool)->not->toHaveKey('outputSchema');
    }

    expect($tools['mcp-read-posts']['annotations'])->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false])
        ->and($tools['mcp-create-post']['annotations'])->toBe(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false])
        ->and($tools['mcp-create-post']['title'])->toBe('Mcp Create Post')
        ->and($tools['mcp-create-post']['description'])->toBe('Create a post for the signed-in person.');
});
