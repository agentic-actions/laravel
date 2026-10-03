<?php

use AgenticActions\OAuth\McpConnection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * A connection binds a client's tokens for a person to the one URL the person approved, and every token
 * without one reaches no action.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
    $this->acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->acme->users()->attach($this->alice);
    $this->other->users()->attach($this->alice);

    $this->flow = new Flow($this);
});

/**
 * The tools a Passport token built in the test lists on a path: the person, the scopes and the client it names.
 *
 * @param  list<string>  $scopes
 * @return list<string>
 */
function actingTokenTools(User $person, array $scopes, ?string $client, string $path): array
{
    app('auth')->forgetGuards();
    Passport::actingAs($person, $scopes, 'api', $client === null ? null : Passport::client()->newQuery()->findOrFail($client));

    return test()->postJson($path, JsonRpc::legacy('tools/list'), ['Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.tools.*.name');
}

it('records the client, the person and the tenant on approval, and nothing while the screen only renders', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();

    expect(McpConnection::query()->count())->toBe(0);

    $this->flow->approve()->assertRedirect();

    expect(McpConnection::for($this->alice)->sole())
        ->client_id->toBe($this->flow->client)
        ->user_type->toBe($this->alice->getMorphClass())
        ->user_id->toBe($this->alice->id)
        ->tenant_type->toBe($this->acme->getMorphClass())
        ->tenant_id->toBe($this->acme->id);
});

it('lists nothing with Acme\'s token on another team of the person, as for an unknown team, or on the base path', function () {
    $token = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    expect($this->flow->tools($token, '/mcp/t/other'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/t/unknown'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/actions'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);
});

it('lists the base tools with a base-path connection, and nothing on a tenant path', function () {
    $token = $this->flow->connect($this->alice, 'http://localhost/mcp/actions', 'actions:read');

    expect(McpConnection::for($this->alice)->sole()->tenant_id)->toBeNull()
        ->and($this->flow->tools($token, '/mcp/actions'))->toBe(['list-my-posts'])
        ->and($this->flow->tools($token, '/mcp/t/acme'))->toBe([]);
});

it('records nothing without a resource, shows no tenant, and its token lists nothing', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, null)->assertOk()->assertDontSee('Only in');
    $this->flow->approve()->assertRedirect();
    $this->flow->token()->assertOk();

    expect(McpConnection::query()->count())->toBe(0)
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/acme'))->toBe([])
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/actions'))->toBe([]);
});

it('revokes a token issued before the connection, and its refresh token, when the person approves', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, null)->assertOk();
    $this->flow->approve();
    $this->flow->token()->assertOk();
    $before = $this->flow->tokens;

    $connected = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $this->flow->mcp($before['access_token'], '/mcp/t/acme')->assertUnauthorized();
    $this->flow->refresh(refreshToken: $before['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect($this->flow->tools($connected, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);
});

it('refuses a connected client asking without the package URL, or for another host, and issues no code', function (?string $resource, ?string $method) {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $this->flow->authorize($this->alice, $resource, method: $method)
        ->assertForbidden()
        ->assertSee('This app cannot be connected to this address.');

    $this->flow->approve()->assertForbidden();

    expect($this->flow->code)->toBeNull();
})->with([
    'no resource, plain' => [null, 'plain'],
    'another host' => ['https://elsewhere.example/mcp/t/acme', 'S256'],
]);

it('moves the connection when the person approves the client for another URL', function () {
    $acme = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $acmeRefresh = $this->flow->tokens['refresh_token'];

    // An unused code for Acme, which the next approval must end.
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $this->flow->approve()->assertRedirect();
    [$acmeCode, $acmeVerifier] = [$this->flow->code, $this->flow->verifier];

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/other')->assertOk()->assertSee('Only in Other.');
    $this->flow->approve()->assertRedirect();
    $this->flow->token('http://localhost/mcp/t/other')->assertOk();

    expect(McpConnection::for($this->alice)->sole()->tenant_id)->toBe($this->other->id)
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/other'))->toBe(['create-team-post', 'list-team-posts'])
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/acme'))->toBe([]);

    $this->flow->mcp($acme, '/mcp/t/acme')->assertUnauthorized();
    $this->flow->refresh(refreshToken: $acmeRefresh)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $this->flow->code = $acmeCode;
    $this->flow->verifier = $acmeVerifier;
    $this->flow->token()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
})->group('database');

it('grants nothing to a connected client\'s token holding only mcp:use or *, and keeps only read and write of the rest', function () {
    Passport::tokensCan([...Passport::$scopes, 'tenant:7' => 'The seventh tenant']);
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    expect(actingTokenTools($this->alice, ['mcp:use'], $this->flow->client, '/mcp/t/acme'))->toBe([])
        ->and(actingTokenTools($this->alice, ['*'], $this->flow->client, '/mcp/t/acme'))->toBe([])
        ->and(actingTokenTools($this->alice, ['actions:read', 'tenant:7'], $this->flow->client, '/mcp/t/acme'))->toBe(['list-team-posts'])
        ->and(actingTokenTools($this->alice, ['actions:read', 'tenant:'.$this->other->id], $this->flow->client, '/mcp/t/other'))->toBe([]);

    $mcpUse = new Flow($this);
    $mcpUse->register();

    expect($mcpUse->tools($mcpUse->connect($this->alice, 'http://localhost/mcp/t/acme', 'mcp:use'), '/mcp/t/acme'))->toBe([]);
});

it('grants nothing on a tenant connection while the tenant prefix is empty', function () {
    $token = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    config(['agentic-actions.abilities.tenant' => '']);

    expect($this->flow->tools($token, '/mcp/t/acme'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/t/other'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/actions'))->toBe([]);
});

it('grants nothing for a connection whose tenant is not the tenant model', function () {
    $token = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    McpConnection::for($this->alice)->update(['tenant_type' => 'post']);

    expect($this->flow->tools($token, '/mcp/t/acme'))->toBe([]);
});

it('runs one connection query for a tools/list of fifteen tools', function () {
    $classes = [];

    foreach (range(1, 15) as $n) {
        $classes[] = $class = "Tests\\Fixtures\\OAuth\\Many\\Read{$n}";

        if (! class_exists($class)) {
            eval("namespace Tests\\Fixtures\\OAuth\\Many; #[\\AgenticActions\\Attributes\\Expose] final class Read{$n} extends \\AgenticActions\\Action { protected string \$description = 'Read {$n}.'; protected ?\\AgenticActions\\Effect \$effect = \\AgenticActions\\Effect::Read; public function authorize(\\AgenticActions\\ActionContext \$context): bool { return true; } public function handle(): string { return 'read'; } }");
        }
    }

    $this->useOAuth(Flow::teams(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]));
    $alice = User::factory()->create();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->users()->attach($alice);
    $flow = new Flow($this);
    $token = $flow->connect($alice, 'http://localhost/mcp/t/acme', 'actions:read');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'agentic_mcp_connections')) {
            $queries[] = $query->sql;
        }
    });

    expect($flow->tools($token, '/mcp/t/acme'))->toHaveCount(15)
        ->and($queries)->toHaveCount(1);
});
