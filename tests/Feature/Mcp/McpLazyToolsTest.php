<?php

use AgenticActions\Discovery\Catalog;
use AgenticActions\Exceptions\MisconfiguredExposure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Mcp\Extra\McpCounted;
use Tests\Fixtures\Mcp\Extra\McpSecret;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Tests\Fixtures\Mcp\McpMembership;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The server builds tools only for the methods that read them: tools/list every listed tool, tools/call the one it
 * names, through the same checks; every other method touches neither the tenant nor the catalog.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->bootMcp(['agentic-actions.discovery.classes' => [McpCounted::class, McpSecret::class]]);

    McpCounted::$schemas = 0;
    McpCounted::$open = true;
    $this->catalogs = 0;

    $this->app->bind(Catalog::class, function (): Catalog {
        $this->catalogs++;

        return new Catalog;
    });

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

afterEach(function () {
    // Teardown runs commands that ask for confirmation in production.
    $this->app['env'] = 'testing';
});

it('answers ping and initialize without the catalog, a schema or a membership check', function () {
    Team::query()->forceCreate(['name' => 'Acme', 'slug' => 'acme']);

    $this->mcp('/mcp/actions', JsonRpc::legacy('ping'), $this->token)->assertOk()->assertJsonPath('result.resultType', 'complete');
    $this->mcp('/mcp/actions', JsonRpc::legacy('initialize', ['protocolVersion' => '2025-11-25']), $this->token)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', config('app.name'));
    $this->mcp('/mcp/t/acme', JsonRpc::legacy('ping'), $this->token)->assertOk();
    $this->mcp('/mcp/t/acme', JsonRpc::legacy('initialize', ['protocolVersion' => '2025-11-25']), $this->token)->assertOk();

    expect($this->catalogs)->toBe(0)
        ->and(McpCounted::$schemas)->toBe(0)
        ->and(McpMembership::$asked)->toBe([]);
});

it('lists every tool the caller may list, sorted by name, with its schema, and builds the catalog once', function () {
    $this->app['env'] = 'production';
    Exceptions::fake();

    $tools = collect($this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $this->token)->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools->keys()->all())->toBe(['mcp-counted', 'mcp-crashes', 'mcp-create-post', 'mcp-keyed', 'mcp-read-posts', 'mcp-translated'])
        ->and($tools['mcp-counted']['inputSchema'])->toEqual(['type' => 'object', 'properties' => ['label' => ['type' => 'string']]])
        ->and(Arr::except($tools['mcp-counted']['annotations'], 'title'))->toBe(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false])
        ->and($this->catalogs)->toBe(1)
        ->and(McpCounted::$schemas)->toBeGreaterThan(0);

    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), '[password]'));
});

it('runs a call to an exposed tool without building the other tools\' schemas', function () {
    $result = $this->mcp('/mcp/actions', JsonRpc::call('mcp-read-posts'), $this->token)->assertOk()->json('result');

    expect($result['isError'])->toBeFalse()
        ->and($result['content'][0]['text'])->toStartWith('Found.')
        ->and(McpCounted::$schemas)->toBe(0);

    $result = $this->mcp('/mcp/actions', JsonRpc::call('mcp-counted', ['label' => 'x']), $this->token)->assertOk()->json('result');

    expect($result['isError'])->toBeFalse()
        ->and(McpCounted::$schemas)->toBeGreaterThan(0);
});

it('refuses as not found a call to a tool the caller may not list, without building its schema', function () {
    McpCounted::$open = false;

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-counted'), $this->token)
        ->assertJsonPath('error.message', 'Tool [mcp-counted] not found.');

    $readOnly = $this->user->createToken('read', ['actions:read'])->plainTextToken;

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-create-post', ['title' => 'Hi', 'body' => 'x']), $readOnly)
        ->assertJsonPath('error.message', 'Tool [mcp-create-post] not found.');

    $this->mcp('/mcp/actions', JsonRpc::call('no-such-tool'), $this->token)
        ->assertJsonPath('error.message', 'Tool [no-such-tool] not found.');

    expect(McpCounted::$schemas)->toBe(0);
});

it('refuses as not found a call to a tool with a forbidden key, and reports it in production', function () {
    $this->app['env'] = 'production';
    Exceptions::fake();

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-secret', ['password' => 'x']), $this->token)
        ->assertJsonPath('error.message', 'Tool [mcp-secret] not found.');

    Exceptions::assertReported(fn (MisconfiguredExposure $exception): bool => str_contains($exception->getMessage(), '[password]'));
});

it('lists nothing and calls nothing for a foreign tenant, asking membership once per tools request', function () {
    Team::query()->forceCreate(['name' => 'Acme', 'slug' => 'acme']);
    McpMembership::$asked = [];

    $this->mcp('/mcp/t/acme', JsonRpc::legacy('tools/list'), $this->token)->assertOk()->assertJsonPath('result.tools', []);
    $this->mcp('/mcp/t/acme', JsonRpc::call('mcp-team-read'), $this->token)->assertJsonPath('error.message', 'Tool [mcp-team-read] not found.');

    expect(McpMembership::$asked)->toBe([null, null])
        ->and($this->catalogs)->toBe(0);
});
