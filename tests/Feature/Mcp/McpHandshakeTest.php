<?php

use Laravel\Mcp\Enums\ErrorCode;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\User;

/*
 * Both handshakes on one route: the legacy initialize with no _meta, and the 2026-07-28 exchange, whose _meta is
 * mirrored in headers. The package adds nothing to either.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    config(['app.name' => 'Acme Notes']);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;
});

describe('the legacy handshake', function () {
    it('answers initialize with the app\'s name, the package version and the instructions line', function () {
        $result = $this->mcp('/mcp/actions', JsonRpc::legacy('initialize', ['protocolVersion' => '2025-11-25']), $this->token)
            ->assertOk()
            ->json('result');

        expect($result['serverInfo'])->toBe(['name' => 'Acme Notes', 'version' => '0.1.0'])
            ->and($result['instructions'])->toBe(trans('agentic-actions::mcp.instructions'))
            ->and($result['protocolVersion'])->toBe('2025-11-25')
            ->and($result['capabilities'])->toHaveKey('tools');
    });

    it('echoes a version it supports, and answers 2025-11-25 for an older one', function (string $asked, string $answered) {
        $this->mcp('/mcp/actions', JsonRpc::legacy('initialize', ['protocolVersion' => $asked]), $this->token)
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', $answered);
    })->with([
        ['2025-11-25', '2025-11-25'],
        ['2025-06-18', '2025-06-18'],
        ['2024-11-05', '2025-11-25'],
    ]);

    it('answers notifications/initialized with 202', function () {
        $this->mcp('/mcp/actions', JsonRpc::notification('notifications/initialized'), $this->token)->assertStatus(202);
    });
});

describe('the 2026-07-28 exchange', function () {
    it('answers server/discover with _meta and the mirrored headers', function () {
        [$body, $headers] = JsonRpc::current('server/discover');

        $result = $this->mcp('/mcp/actions', $body, $this->token, $headers)->assertOk()->json('result');

        expect($result['supportedVersions'])->toBe(['2026-07-28'])
            ->and($result['instructions'])->toBe(trans('agentic-actions::mcp.instructions'))
            ->and($result['_meta']['io.modelcontextprotocol/serverInfo']['name'])->toBe('Acme Notes');
    });

    it('leaves laravel/mcp\'s header validation in place', function () {
        [$body] = JsonRpc::current('server/discover');

        $this->mcp('/mcp/actions', $body, $this->token)
            ->assertStatus(400)
            ->assertJsonPath('error.code', ErrorCode::HEADER_MISMATCH->value);
    });
});
