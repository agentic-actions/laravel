<?php

use AgenticActions\OAuth\McpConnection;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The whole flow in one process, shaped as Claude runs it, from the 401 to a refresh.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
    $this->acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->acme->users()->attach($this->alice);
    $this->other->users()->attach($this->alice);

    Post::query()->forceCreate(['user_id' => $this->alice->id, 'team_id' => $this->acme->id, 'title' => 'Acme plan', 'body' => 'x', 'status' => 'draft']);

    $this->flow = new Flow($this);
});

it('runs Claude\'s flow on a tenant path, from the 401 to a refresh', function () {
    $this->flow->mcp(null, '/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/acme')->assertOk()->assertExactJson([
        'resource' => 'http://localhost/mcp/t/acme',
        'authorization_servers' => ['http://localhost'],
        'scopes_supported' => ['actions:read', 'actions:write'],
    ]);

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256']);

    $this->flow->register('https://claude.ai/api/mcp/auth_callback');

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')
        ->assertOk()
        ->assertSee('Connect Claude to Laravel?')
        ->assertSee('After you answer, you go to claude.ai.')
        ->assertSee('Only in Acme.')
        ->assertSee('Read what you can see')
        ->assertSee('Create and change what you can change');

    $approval = $this->flow->approve();

    expect($approval->getStatusCode())->toBe(302)
        ->and($approval->headers->get('Location'))->toStartWith('https://claude.ai/api/mcp/auth_callback?code=')
        ->and($this->flow->redirected($approval)['state'])->toBe('st123')
        ->and(McpConnection::for($this->alice)->sole())
        ->client_id->toBe($this->flow->client)
        ->tenant_id->toBe($this->acme->id);

    $this->flow->token('http://localhost/mcp/t/acme')->assertOk()->assertJsonPath('token_type', 'Bearer');
    $token = $this->flow->accessToken();

    expect($this->flow->tools($token, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);

    $this->flow->mcp($token, '/mcp/t/acme', 'tools/call', ['name' => 'create-team-post', 'arguments' => ['title' => 'From Claude']])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(Post::query()->where('title', 'From Claude')->sole())
        ->user_id->toBe($this->alice->id)
        ->team_id->toBe($this->acme->id);

    $this->flow->refresh('http://localhost/mcp/t/acme')->assertOk();

    $this->flow->mcp($token, '/mcp/t/acme')->assertUnauthorized();

    expect($this->flow->tools($this->flow->accessToken(), '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);
});

it('runs the flow on the base path of an app without tenants', function () {
    $this->useOAuth(Flow::teams(['agentic-actions.tenant.model' => null]));
    $alice = User::factory()->create();
    $flow = new Flow($this);

    $flow->mcp(null, '/mcp/actions')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/actions", scope="actions:read actions:write"');

    $token = $flow->connect($alice, 'http://localhost/mcp/actions');

    expect(McpConnection::for($alice)->sole())->tenant_type->toBeNull()->tenant_id->toBeNull()
        ->and($flow->tools($token, '/mcp/actions'))->toBe(['create-team-post', 'list-my-posts', 'list-team-posts']);
});

it('issues no token for a wrong or missing code verifier', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $this->flow->approve()->assertRedirect();

    $this->flow->token(fields: ['code_verifier' => ''])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    $this->flow->token(fields: ['code_verifier' => str_repeat('x', 64)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect($this->flow->tokens)->toBe([]);
});

it('lists only the Read tools after a refresh narrowed to actions:read, still only on Acme', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $this->flow->refresh(scope: 'actions:read')->assertOk();

    expect($this->flow->tools($this->flow->accessToken(), '/mcp/t/acme'))->toBe(['list-team-posts'])
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/other'))->toBe([]);
});

it('keeps the binding through a refresh that names another resource', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $this->flow->refresh('http://localhost/mcp/t/other')->assertOk();

    expect($this->flow->tools($this->flow->accessToken(), '/mcp/t/other'))->toBe([])
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);
});
