<?php

use AgenticActions\OAuth\OAuthServiceProvider;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Fixtures\OAuth\Flow;

/*
 * With OAuth on, the package's paths answer discovery with their own scopes: the 401 challenge and the
 * protected-resource metadata, whichever order the routes register in.
 */

it('challenges each package path with its own metadata URL and its own scopes', function () {
    $this->useOAuth(Flow::teams());
    $flow = new Flow($this);

    $flow->mcp(null, '/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');

    // The base path serves one Read only.
    $flow->mcp(null, '/mcp/actions')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/actions", scope="actions:read"');
});

it('lists each package path\'s scopes in its metadata, an unknown tenant\'s too with no query, and leaves other paths alone', function () {
    $this->useOAuth(Flow::teams());

    $this->getJson('/.well-known/oauth-protected-resource/mcp/actions')->assertOk()->assertExactJson([
        'resource' => 'http://localhost/mcp/actions',
        'authorization_servers' => ['http://localhost'],
        'scopes_supported' => ['actions:read'],
    ]);

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/acme')->assertJsonPath('scopes_supported', ['actions:read', 'actions:write']);

    DB::enableQueryLog();

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/unknown')->assertJsonPath('scopes_supported', ['actions:read', 'actions:write']);

    expect(DB::getQueryLog())->toBe([]);

    $this->getJson('/.well-known/oauth-protected-resource/anything/at/all')->assertExactJson([
        'resource' => 'http://localhost/anything/at/all',
        'authorization_servers' => ['http://localhost'],
        'scopes_supported' => ['mcp:use'],
    ]);
});

it('holds the metadata and the challenge whether the OAuth routes register before or after the package\'s provider boots', function (string $when) {
    $this->useOAuth(Flow::teams(), routes: $when);

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/acme')->assertJsonPath('scopes_supported', ['actions:read', 'actions:write']);

    (new Flow($this))->mcp(null, '/mcp/t/acme')
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');
})->with(['before' => 'register', 'after' => 'boot']);

it('answers 0.6\'s challenge on a package path while OAuth is on without Mcp::oauthRoutes()', function () {
    $this->useOAuth(Flow::teams(), routes: null);

    (new Flow($this))->mcp(null, '/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

it('merges its scopes into Passport\'s, keeping the app\'s own scopes and descriptions and laravel/mcp\'s', function () {
    $this->useOAuth(Flow::teams());

    Passport::tokensCan(['actions:read' => 'The app\'s own words', 'admin' => 'Administer']);
    (new OAuthServiceProvider(app()))->boot();

    expect(Passport::$scopes)->toBe([
        'actions:read' => 'The app\'s own words',
        'actions:write' => 'Create and change what you can change',
        'admin' => 'Administer',
    ]);

    $this->useOAuth(Flow::teams());

    expect(Passport::$scopes)->toMatchArray([
        'actions:read' => 'The app\'s own words',
        'actions:write' => 'Create and change what you can change',
        'mcp:use' => 'Use MCP server',
    ]);
});
