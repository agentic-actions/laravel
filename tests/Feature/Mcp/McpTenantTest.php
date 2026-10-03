<?php

use AgenticActions\Effect;
use Tests\Fixtures\Mcp\ArabicUser;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\McpEnvironment;
use Tests\Fixtures\Mcp\McpMembership;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The tenant path. A bound token reaches only its tenant, the tenant path serves only tenant-scoped actions and
 * the base path only the rest, and an unknown tenant and a foreign one answer alike.
 */

uses(McpEnvironment::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->globex = Team::factory()->create(['slug' => 'globex']);
    $this->acme->users()->attach($this->user);
    $this->globex->users()->attach($this->user);

    Post::query()->forceCreate(['user_id' => $this->user->id, 'team_id' => $this->acme->id, 'title' => 'Acme plan', 'body' => 'x', 'status' => 'draft']);
    Post::query()->forceCreate(['user_id' => $this->user->id, 'team_id' => $this->globex->id, 'title' => 'Globex plan', 'body' => 'x', 'status' => 'draft']);

    McpMembership::$asked = [];
});

/**
 * The tool names a token lists on a path.
 *
 * @return list<string>
 */
function mcpTenantTools(string $path, string $token): array
{
    return test()->mcp($path, JsonRpc::legacy('tools/list'), $token)->assertOk()->json('result.tools.*.name');
}

it('lists a bound token\'s tools on its tenant\'s path only, although the person belongs to both', function () {
    $token = $this->user->createToken('acme', ['actions:read', 'tenant:'.$this->acme->id])->plainTextToken;

    expect(mcpTenantTools('/mcp/t/acme', $token))->toBe(['mcp-team-read'])
        ->and(mcpTenantTools('/mcp/t/globex', $token))->toBe([]);

    $this->mcp('/mcp/t/acme', JsonRpc::call('mcp-team-read'), $token)
        ->assertJsonPath('result.content.0.text', "Found.\n---\n".json_encode(['titles' => ['Acme plan']]));

    $this->mcp('/mcp/t/globex', JsonRpc::call('mcp-team-read'), $token)
        ->assertJsonPath('error.message', 'Tool [mcp-team-read] not found.');
});

it('lists an unbound token\'s tools on every tenant the person belongs to, and nothing elsewhere', function () {
    $token = $this->user->createToken('any', ['actions:read'])->plainTextToken;
    $initech = Team::factory()->create(['slug' => 'initech']);

    expect(mcpTenantTools('/mcp/t/globex', $token))->toBe(['mcp-team-read'])
        ->and(mcpTenantTools('/mcp/t/initech', $token))->toBe([]);

    $this->mcp('/mcp/t/globex', JsonRpc::call('mcp-team-read'), $token)
        ->assertJsonPath('result.content.0.text', "Found.\n---\n".json_encode(['titles' => ['Globex plan']]));

    $initech->users()->attach($this->user);

    expect(mcpTenantTools('/mcp/t/initech', $token))->toBe(['mcp-team-read']);
});

it('serves account-level actions on the base path only, and a bound token reaches none of them', function () {
    $unbound = $this->user->createToken('any', ['actions:read'])->plainTextToken;
    $bound = $this->user->createToken('acme', ['actions:read', 'tenant:'.$this->acme->id])->plainTextToken;

    expect(mcpTenantTools('/mcp/actions', $unbound))->toBe(['mcp-read-posts'])
        ->and(mcpTenantTools('/mcp/t/acme', $unbound))->toBe(['mcp-team-read'])
        ->and(mcpTenantTools('/mcp/actions', $bound))->toBe([])
        ->and(mcpTenantTools('/mcp/t/acme', $bound))->not->toContain('mcp-read-posts');

    $this->mcp('/mcp/t/acme', JsonRpc::call('mcp-read-posts'), $bound)
        ->assertJsonPath('error.message', 'Tool [mcp-read-posts] not found.');

    $this->mcp('/mcp/actions', JsonRpc::call('mcp-team-read'), $unbound)
        ->assertJsonPath('error.message', 'Tool [mcp-team-read] not found.');
});

it('resolves a slug-routed team by slug and binds tokens by its id', function () {
    $byId = $this->user->createToken('id', ['actions:read', 'tenant:'.$this->acme->id])->plainTextToken;
    $bySlug = $this->user->createToken('slug', ['actions:read', 'tenant:acme'])->plainTextToken;

    expect(mcpTenantTools('/mcp/t/acme', $byId))->toBe(['mcp-team-read'])
        ->and(mcpTenantTools('/mcp/t/'.$this->acme->id, $byId))->toBe([])
        ->and(mcpTenantTools('/mcp/t/acme', $bySlug))->toBe([]);
});

it('answers an unknown and a foreign tenant with the same bytes, in the person\'s language, after one membership check', function () {
    $outsider = ArabicUser::query()->create(['name' => 'Salma', 'email' => 'salma@example.test', 'password' => 'secret']);
    $token = $outsider->createToken('client', ['actions:read', 'actions:write'])->plainTextToken;

    expect(app()->getLocale())->toBe('en');

    $answers = [];
    $asked = [];

    foreach (['acme', 'no-such-team'] as $segment) {
        McpMembership::$asked = [];

        $answers[$segment] = [
            $this->mcp("/mcp/t/{$segment}", JsonRpc::legacy('initialize', ['protocolVersion' => '2025-11-25'], id: 7), $token)->getContent(),
            $this->mcp("/mcp/t/{$segment}", JsonRpc::legacy('tools/list', id: 8), $token)->getContent(),
            $this->mcp("/mcp/t/{$segment}", JsonRpc::call('mcp-team-read', id: 9), $token)->getContent(),
        ];

        $asked[$segment] = McpMembership::$asked;
    }

    expect($answers['acme'])->toBe($answers['no-such-team'])
        ->and(json_decode($answers['acme'][0], true)['result']['instructions'])->toBe(trans('agentic-actions::mcp.instructions', [], 'ar'))
        ->and(json_decode($answers['acme'][1], true)['result']['tools'])->toBe([])
        ->and($asked['acme'])->toBe([null, null, null])
        ->and($asked['no-such-team'])->toBe([]);
});

it('asks membership with each action\'s effect once the person may enter', function () {
    $token = $this->user->createToken('any', ['actions:read'])->plainTextToken;

    mcpTenantTools('/mcp/t/acme', $token);

    expect(McpMembership::$asked)->toBe([null, Effect::Read]);
});
