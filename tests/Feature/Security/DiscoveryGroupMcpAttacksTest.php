<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Discovery\Groups\Billing\ChargeCard;
use Tests\Fixtures\Discovery\Groups\Billing\RefundCharge;
use Tests\Fixtures\Mcp\JsonRpc;
use Workbench\App\Models\User;

/*
 * An MCP client with a token that may do anything, against the mounted MCP route, while an optional module is left
 * out of the deployment but its classes still autoload and a stale config still lists them. It must neither list nor
 * call the module's tools.
 */

beforeEach(function () {
    // Every group fixture offers agents, an exposure error without laravel/ai.
    $this->skipUnlessAi();

    $fixtures = dirname(__DIR__, 2).'/Fixtures/Discovery/Groups';
    $this->root = 'agentic-group-mcp-'.getmypid();

    File::copyDirectory($fixtures.'/Core', base_path($this->root.'/app'));
    File::copyDirectory($fixtures.'/Billing', base_path($this->root.'/modules/Billing/src'));

    config(['agentic-actions.discovery.groups' => [
        'core' => [
            'paths' => [$this->root.'/app'],
            'snapshot' => $this->root.'/actions.exposure.json',
        ],
        'billing' => [
            'paths' => [$this->root.'/modules/Billing/src'],
            'classes' => [ChargeCard::class, RefundCharge::class],
            'snapshot' => $this->root.'/modules/Billing/actions.exposure.json',
            'optional' => true,
        ],
    ]]);

    $this->refreshActions();

    $this->billing = User::factory()->create(['email' => 'billing@example.test']);
    $this->token = $this->billing->createToken('everything', ['actions:read', 'actions:write', 'actions:destructive', 'actions:external'])->plainTextToken;
});

afterEach(function () {
    if (! isset($this->root)) {
        return;
    }

    File::deleteDirectory(base_path($this->root));
});

/**
 * POST one JSON-RPC message to the mounted MCP route with the billing contact's token, as a fresh request.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function groupMcp(array $body): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/mcp/actions', $body, [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.test()->token,
    ]);
}

/**
 * The tool names tools/list gives the billing contact.
 *
 * @return list<string>
 */
function groupMcpTools(): array
{
    return groupMcp(JsonRpc::legacy('tools/list'))->assertOk()->json('result.tools.*.name');
}

it('lists and calls the module\'s tool while the module is installed', function () {
    expect(groupMcpTools())->toBe(['charge-card', 'rename-team']);

    groupMcp(JsonRpc::call('charge-card', ['cents' => 100]))
        ->assertOk()
        ->assertJsonPath('result.isError', false);
});

it('neither lists nor calls the module\'s tools once the module is gone, though its classes still autoload', function () {
    File::deleteDirectory(base_path($this->root.'/modules'));
    $this->refreshActions();

    expect(class_exists(ChargeCard::class))->toBeTrue()
        ->and(groupMcpTools())->toBe(['rename-team']);

    foreach (['charge-card' => ['cents' => 100], 'refund-charge' => ['charge' => 'ch_1']] as $name => $arguments) {
        groupMcp(JsonRpc::call($name, $arguments))
            ->assertJsonPath('error.message', "Tool [{$name}] not found.");
    }

    groupMcp(JsonRpc::call('rename-team', ['name' => 'Acme']))
        ->assertOk()
        ->assertJsonPath('result.isError', false);
});
