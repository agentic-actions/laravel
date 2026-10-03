<?php

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\OAuth\McpConnection;
use AgenticActions\Security\TokenGrants;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\TransientToken;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The reader knows Passport. Its cookie reads as a session, and its tokens grant nothing but on the package MCP path
 * their connection names.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create();
    $this->acme = Team::factory()->create(['slug' => 'acme']);
    $this->acme->users()->attach($this->alice);
});

/**
 * List the tools on a path as the person, acting through this Passport token on the api guard.
 *
 * @param  AccessToken<mixed>|TransientToken  $token
 * @return list<string>
 */
function toolsActingWith(User $person, AccessToken|TransientToken $token, string $path): array
{
    app('auth')->forgetGuards();
    $person->withAccessToken($token);
    Auth::guard('api')->setUser($person);
    Auth::shouldUse('api');

    return test()->postJson($path, JsonRpc::legacy('tools/list'), ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.tools.*.name');
}

it('describes the passport driver, and logs no notice for a Passport token', function () {
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logged->append($event->message));

    expect(app(TokenGrants::class)->describe('api'))->toBe('passport')
        ->and(toolsActingWith($this->alice, new AccessToken(['oauth_client_id' => 'no-such-client', 'oauth_scopes' => ['actions:read']]), '/mcp/t/acme'))->toBe([])
        ->and($logged->getArrayCopy())->toBe([]);
});

it('reads Passport\'s cookie token as a session on HTTP, which grants nothing inside MCP', function () {
    $this->alice->withAccessToken(new TransientToken);

    expect(app(ReadsTokenGrants::class)->grants($this->alice, Auth::guard('api')))->toBeNull()
        ->and(toolsActingWith($this->alice, new TransientToken, '/mcp/t/acme'))->toBe([]);
});

it('grants nothing to a connected Passport token on a route that is not the package\'s MCP path', function () {
    $flow = new Flow($this);
    $flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $token = new AccessToken(['oauth_client_id' => $flow->client, 'oauth_user_id' => $this->alice->id, 'oauth_scopes' => ['actions:read', 'actions:write']]);
    $this->alice->withAccessToken($token);

    $request = Request::create('/api/posts', 'POST');
    $request->setRouteResolver(fn () => (new Route(['POST'], 'api/posts', fn () => null))->middleware('auth:api'));
    app()->instance('request', $request);

    expect(app(ReadsTokenGrants::class)->grants($this->alice, Auth::guard('api')))->toBe([])
        ->and(McpConnection::for($this->alice)->exists())->toBeTrue();
});

it('grants nothing, and raises nothing, for a Passport::actingAs() token without a client', function () {
    app('auth')->forgetGuards();
    Passport::actingAs($this->alice, ['actions:read', 'actions:write']);

    expect(test()->postJson('/mcp/t/acme', JsonRpc::legacy('tools/list'), ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.tools.*.name'))->toBe([]);
});

it('lists the tenant\'s tools for the docs\' testing recipe: a connection, and a token naming its client', function () {
    $client = Client::factory()->create();
    $connection = new McpConnection(['client_id' => $client->id, 'scopes' => ['actions:read']]);
    $connection->user()->associate($this->alice)->tenant()->associate($this->acme)->save();

    app('auth')->forgetGuards();
    $this->alice->withAccessToken(new AccessToken(['oauth_client_id' => $client->id, 'oauth_user_id' => $this->alice->id, 'oauth_scopes' => ['actions:read']]));
    $this->actingAs($this->alice, 'api');

    $this->postJson('/mcp/t/'.$this->acme->slug, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertJsonPath('result.tools.0.name', 'list-team-posts');
});
