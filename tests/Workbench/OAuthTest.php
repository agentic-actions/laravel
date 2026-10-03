<?php

use AgenticActions\OAuth\McpConnection;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * On the workbench, a Claude-shaped client connects to one team's MCP URL with OAuth, beside a Sanctum token of the
 * same person, and reaches that team only until the person revokes it.
 */

beforeEach(function () {
    $this->useOAuth();

    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
    $this->acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->acme->users()->attach($this->alice);
    $this->other->users()->attach($this->alice);
});

it('connects a client to one team beside a Sanctum token, keeps it there through a refresh, and ends it on revoke', function () {
    $flow = new Flow($this);
    $sanctum = $this->alice->createToken('any', ['actions:read'])->plainTextToken;

    $flow->mcp(null, '/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');

    $flow->register('https://claude.ai/api/mcp/auth_callback');
    $flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()->assertSee('Only in Acme.')->assertSee('After you answer, you go to claude.ai.');
    $flow->approve()->assertRedirect();
    $flow->token('http://localhost/mcp/t/acme')->assertOk();
    $token = $flow->accessToken();

    expect($flow->tools($token, '/mcp/t/acme'))->toBe(['import-posts', 'list-team-posts'])
        ->and($flow->tools($token, '/mcp/t/other'))->toBe([])
        ->and($flow->tools($token, '/mcp/actions'))->toBe([])
        ->and($flow->tools($sanctum, '/mcp/t/other'))->toBe(['list-team-posts']);

    $flow->mcp($token, '/mcp/t/acme', 'tools/call', ['name' => 'import-posts', 'arguments' => ['titles' => ['From Claude']]])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Done.');

    expect(Post::query()->sole()->only(['user_id', 'team_id', 'title']))->toBe(['user_id' => $this->alice->id, 'team_id' => $this->acme->id, 'title' => 'From Claude']);

    $flow->refresh('http://localhost/mcp/t/other')->assertOk();

    expect($flow->tools($flow->accessToken(), '/mcp/t/acme'))->toBe(['import-posts', 'list-team-posts'])
        ->and($flow->tools($flow->accessToken(), '/mcp/t/other'))->toBe([]);

    McpConnection::for($this->alice)->sole()->revoke();

    $flow->mcp($flow->accessToken(), '/mcp/t/acme')->assertUnauthorized();

    expect($flow->tools($sanctum, '/mcp/t/acme'))->toBe(['list-team-posts']);
});
