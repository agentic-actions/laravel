<?php

use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * One per-minute budget per person, shared by all their tokens and tenant paths, counted before the server runs.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    config(['agentic-actions.mcp.per_minute' => 2]);

    $this->user = User::factory()->create();
});

/**
 * POST a tools/list with a token and return the status.
 */
function mcpStatus(string $token, string $path = '/mcp/actions'): int
{
    return test()->mcp($path, JsonRpc::legacy('tools/list'), $token)->status();
}

it('answers 429 once the budget is spent, before the server runs, and 200 again a minute later', function () {
    $token = $this->user->createToken('client', ['actions:read'])->plainTextToken;

    expect([mcpStatus($token), mcpStatus($token), mcpStatus($token)])->toBe([200, 200, 429]);

    $this->travel(61)->seconds();

    expect(mcpStatus($token))->toBe(200);
});

it('shares one budget between two tokens of one person, and gives another person a budget of their own', function () {
    $first = $this->user->createToken('first', ['actions:read'])->plainTextToken;
    $second = $this->user->createToken('second', ['actions:write'])->plainTextToken;
    $theirs = User::factory()->create()->createToken('theirs', ['actions:read'])->plainTextToken;

    expect([mcpStatus($first), mcpStatus($second), mcpStatus($first), mcpStatus($theirs)])->toBe([200, 200, 429, 200]);
});

it('shares one budget across the person\'s own team, a foreign team and an unknown slug', function () {
    $own = Team::factory()->create(['slug' => 'acme']);
    $own->users()->attach($this->user);
    Team::factory()->create(['slug' => 'globex']);

    $token = $this->user->createToken('client', ['actions:read'])->plainTextToken;

    expect([mcpStatus($token, '/mcp/t/acme'), mcpStatus($token, '/mcp/t/globex'), mcpStatus($token, '/mcp/t/nobody')])->toBe([200, 200, 429])
        ->and(mcpStatus($token, '/mcp/actions'))->toBe(429);
});
