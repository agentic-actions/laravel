<?php

use AgenticActions\OAuth\BindConsent;
use AgenticActions\OAuth\McpConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Install\PassportUser;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\OAuth\Flow;
use Tests\Fixtures\OAuth\Queued\QueueTeamPost;
use Tests\Fixtures\Queue\Queued;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The 0.7 security pass: attacks on the consent screen, the binding and the reader, each kept as a test. Every one
 * ends with no token reaching an action the person did not approve on a screen they saw.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->third = Team::factory()->create(['name' => 'Third', 'slug' => 'third']);
    $this->acme->users()->attach([$this->alice->id, $this->bob->id]);
    $this->other->users()->attach($this->alice);
    $this->third->users()->attach($this->alice);

    $this->flow = new Flow($this);
});

/**
 * Run BindConsent's approval for a screen the session showed, with a stand-in for Passport that issues a code.
 *
 * @return array{0: Closure(): mixed, 1: string} the approval, and the code it issues
 */
function concurrentApproval(User $person, string $client, Team $tenant): array
{
    $code = 'code-'.$tenant->slug;
    $session = new Store('test', new ArraySessionHandler(10));
    $session->put('authToken', "token-{$tenant->slug}");
    $session->put(BindConsent::SESSION, ['auth_token' => "token-{$tenant->slug}", 'client_id' => $client, 'user' => $person->getMorphClass().':'.$person->getKey(), 'tenant_type' => $tenant->getMorphClass(), 'tenant_id' => $tenant->getKey(), 'scopes' => ['actions:read', 'actions:write']]);

    $request = Request::create('/oauth/authorize', 'POST', ['client_id' => $client, 'auth_token' => "token-{$tenant->slug}"]);
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $person);

    $passport = function () use ($person, $client, $code) {
        Passport::authCode()->newQuery()->forceCreate(['id' => $code, 'user_id' => $person->getKey(), 'client_id' => $client, 'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addMinutes(10)]);

        return redirect("http://127.0.0.1:8765/callback?code={$code}&state=st");
    };

    return [fn () => (new BindConsent)->handle($request, $passport), $code];
}

/**
 * Run the first approval while the second lands after the first read the connection, then say which codes still work.
 *
 * @return array{0: mixed, 1: list<string>}
 */
function interleavedApprovals(array $first, array $second): array
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $second): void {
        if (! $fired && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'agentic_mcp_connections')) {
            $fired = true;
            $second[0]();
        }
    });

    try {
        $answer = $first[0]();
    } catch (HttpException $exception) {
        $answer = $exception->getStatusCode();
    }

    // Passport's code id is a fixed-width column, which Postgres pads.
    return [$answer, array_map(trim(...), Passport::authCode()->newQuery()->where('revoked', false)->pluck('id')->all())];
}

it('refuses a code used twice', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $this->flow->approve();
    $this->flow->token()->assertOk();
    $first = $this->flow->tokens;

    $this->flow->token()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect($this->flow->tokens)->toBe($first);
});

it('names a client that poses as another app by what it is, with its real host and no reordering characters', function () {
    $this->flow->register('https://evil.example/callback', name: "Claude\u{202E}\u{2066}\u{200F}\u{061C} (official)");

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertSee('Connect Claude     (official) to Laravel?')
        ->assertSee('After you answer, you go to evil.example.')
        ->assertDontSee("\u{202E}", false)
        ->assertDontSee("\u{200F}", false);
});

it('escapes a client name that holds markup', function () {
    $this->flow->register('https://claude.ai/api/mcp/auth_callback', name: '<script>alert(1)</script>');

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('records nothing or exactly the tenant the screen names, whatever the resource\'s spelling', function (string $resource, ?string $tenant) {
    $this->flow->register();
    $page = $this->flow->authorize($this->alice, $resource);

    if ($page->getStatusCode() === 200) {
        $this->flow->approve();
    }

    expect(McpConnection::query()->first()?->tenant_id)->toBe($tenant === null ? null : Team::query()->where('slug', $tenant)->value('id'));
})->with([
    'user info' => ['http://alice@localhost/mcp/t/acme', null],
    'an uppercase host' => ['http://LOCALHOST/mcp/t/acme', null],
    'a trailing-dot host' => ['http://localhost./mcp/t/acme', null],
    'an explicit port' => ['http://localhost:80/mcp/t/acme', null],
    'another scheme' => ['https://localhost/mcp/t/acme', null],
    'an encoded path' => ['http://localhost/mcp/t/%61cme', 'acme'],
    'an encoded slash' => ['http://localhost/mcp/t/ac%2Fme', null],
    'dot segments' => ['http://localhost/mcp/t/other/../acme', null],
    'a query naming another team' => ['http://localhost/mcp/t/acme?team=other', 'acme'],
    'a trailing slash' => ['http://localhost/mcp/t/acme/', 'acme'],
    'a doubled slash' => ['http://localhost//mcp/t/acme', null],
    'a fragment' => ['http://localhost/mcp/t/acme#other', null],
    'the metadata path' => ['http://localhost/.well-known/oauth-protected-resource/mcp/t/acme', null],
    'another path' => ['http://localhost/api/mcp/t/acme', null],
]);

it('ends two approvals at once for different URLs, for a connected client, with one connection and one working code', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $other = concurrentApproval($this->alice, $this->flow->client, $this->other);
    $third = concurrentApproval($this->alice, $this->flow->client, $this->third);

    [$answer, $working] = interleavedApprovals($other, $third);

    expect($answer->getStatusCode())->toBe(302)
        ->and($working)->toBe([$other[1]])
        ->and(McpConnection::for($this->alice)->sole()->tenant_id)->toBe($this->other->id);
})->group('database');

it('leaves no connection and no working code when two first approvals land at once', function () {
    $this->flow->register();
    $other = concurrentApproval($this->alice, $this->flow->client, $this->other);
    $third = concurrentApproval($this->alice, $this->flow->client, $this->third);

    [$answer, $working] = interleavedApprovals($other, $third);

    expect($answer)->toBe(403)
        ->and($working)->toBe([])
        ->and(McpConnection::query()->count())->toBe(0);
})->group('database');

it('refuses an approval posted after the person switched accounts, and issues no code', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();

    $this->actingAs($this->bob, 'web');

    $this->flow->approve()->assertForbidden();

    expect($this->flow->code)->toBeNull()
        ->and(McpConnection::query()->count())->toBe(0)
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
});

it('reads a token sent with another person\'s session as nobody\'s mix: the session grants nothing inside MCP', function () {
    $token = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    app('auth')->forgetGuards();
    $this->actingAs($this->bob, 'web');

    $listed = $this->postJson('/mcp/t/acme', JsonRpc::legacy('tools/list'), ['Accept' => 'application/json, text/event-stream', 'Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('result.tools.*.name');

    expect($listed)->toBe([])
        ->and($this->flow->tools($token, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);

    $this->flow->mcp($token, '/mcp/t/acme', 'tools/call', ['name' => 'create-team-post', 'arguments' => ['title' => 'Mine']])->assertOk();

    expect(Post::query()->sole()->user_id)->toBe($this->alice->id);
});

it('grants nothing to another person\'s token for a client the first person connected', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $bob = new Flow($this);
    $bob->client = $this->flow->client;
    $bob->redirect = $this->flow->redirect;
    $bob->authorize($this->bob, null)->assertOk();
    $bob->approve()->assertRedirect();
    $bob->token()->assertOk();

    expect($bob->tools($bob->accessToken(), '/mcp/t/acme'))->toBe([]);
});

it('checks the client the query names, not one a JSON body names', function () {
    $good = new Flow($this);
    $good->register();
    $this->flow->register(grants: ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code']);

    $query = http_build_query(['response_type' => 'code', 'client_id' => $this->flow->client, 'redirect_uri' => $this->flow->redirect, 'scope' => 'actions:read', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'resource' => 'http://localhost/mcp/t/acme']);

    $this->actingAs($this->alice, 'web')
        ->call('GET', '/oauth/authorize?'.$query, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['client_id' => $good->client, 'prompt' => 'none']))
        ->assertForbidden();
});

it('shows the screen to a connected client whose JSON body asks Passport to skip it', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $query = http_build_query(['response_type' => 'code', 'client_id' => $this->flow->client, 'redirect_uri' => $this->flow->redirect, 'scope' => 'actions:read', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'resource' => 'http://localhost/mcp/t/acme']);

    $this->actingAs($this->alice, 'web')
        ->call('GET', '/oauth/authorize?'.$query, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['prompt' => 'none']))
        ->assertOk()
        ->assertSee('Only in Acme.');
});

it('answers malformed parameters without a 500', function (array $extra, int $status) {
    $this->flow->register();

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', extra: $extra)->assertStatus($status);
})->with([
    'scope as a list' => [['scope' => ['actions:read']], 400],
    'code_challenge_method as a list' => [['code_challenge_method' => ['S256']], 400],
    'resource as a list' => [['resource' => ['http://localhost/mcp/t/acme']], 200],
    'client_id as a list' => [['client_id' => ['x']], 400],
]);

/*
 * The review after the build: each attack the 0.7 security review ran, kept as a test.
 */

it('keeps the URL the person approved when the token request names another', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $this->flow->approve()->assertRedirect();
    $this->flow->token('http://localhost/mcp/t/other')->assertOk();
    $token = $this->flow->accessToken();

    expect($this->flow->tools($token, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts'])
        ->and($this->flow->tools($token, '/mcp/t/other'))->toBe([])
        ->and($this->flow->tools($token, '/mcp/actions'))->toBe([]);
});

it('refuses a tenant the person is not in, however the resource spells it', function (string $resource) {
    Team::factory()->create(['name' => 'Bobs', 'slug' => 'bobs'])->users()->attach($this->bob);
    $this->flow->register();

    $this->flow->authorize($this->alice, $resource)->assertForbidden();

    expect(session(BindConsent::SESSION))->toBeNull();
})->with([
    'plain' => 'http://localhost/mcp/t/bobs',
    'an encoded segment' => 'http://localhost/mcp/t/%62obs',
    'a trailing slash' => 'http://localhost/mcp/t/bobs/',
    'a query naming a team of the person' => 'http://localhost/mcp/t/bobs?team=acme',
]);

it('takes an approval only with the CSRF token the screen posts', function () {
    $this->flow->register();
    $page = (string) $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()->getContent();
    preg_match('/name="_token" value="([^"]+)"/', $page, $field);

    // Laravel's CSRF check stands aside while tests run; the app runs it everywhere else.
    $this->app['env'] = 'production';

    $this->flow->approve()->assertStatus(419);

    expect($this->flow->code)->toBeNull()
        ->and(McpConnection::query()->count())->toBe(0)
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);

    $this->flow->approve(['_token' => $field[1]])->assertRedirect();

    expect($this->flow->code)->not->toBeNull()
        ->and(McpConnection::for($this->alice)->sole()->tenant_id)->toBe($this->acme->id);
});

it('refuses an approval posted a second time, and issues no second code', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $screen = session('authToken');
    $this->flow->approve()->assertRedirect();

    $this->flow->approve(['auth_token' => $screen])->assertForbidden();

    expect(Passport::authCode()->newQuery()->count())->toBe(1)
        ->and(McpConnection::query()->count())->toBe(1);
});

it('changes nothing when the person denies a screen and then posts its Allow', function () {
    $acme = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/other')->assertOk();
    $screen = session('authToken');
    $this->flow->deny()->assertRedirect();

    $this->flow->approve(['auth_token' => $screen])->assertForbidden();

    expect($this->flow->code)->toBeNull()
        ->and(McpConnection::for($this->alice)->sole()->tenant_id)->toBe($this->acme->id)
        ->and($this->flow->tools($acme, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts']);
})->group('database');

it('checks the client the query names on a HEAD request, as on a GET', function () {
    $good = new Flow($this);
    $good->register();
    $this->flow->register(grants: ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code']);

    $query = http_build_query(['response_type' => 'code', 'client_id' => $this->flow->client, 'redirect_uri' => $this->flow->redirect, 'scope' => 'actions:read', 'state' => 's', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'resource' => 'http://localhost/mcp/t/acme']);

    $this->actingAs($this->alice, 'web')
        ->call('HEAD', '/oauth/authorize?'.$query, [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['client_id' => $good->client]))
        ->assertForbidden();

    expect(session(BindConsent::SESSION))->toBeNull();
});

it('names the host a browser goes to, or connects nothing, for a redirect URI a client registers with user info', function (string $redirect, ?string $host) {
    $registered = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]]);

    if ($registered->getStatusCode() === 400) {
        expect($registered->json('error'))->toBe('invalid_redirect_uri');

        return;
    }

    [$this->flow->client, $this->flow->redirect] = [(string) $registered->json('client_id'), $redirect];
    $page = $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme');

    $host === null
        ? $page->assertForbidden()
        : $page->assertOk()->assertSee("After you answer, you go to {$host}.")->assertDontSee('you go to claude.ai');
})->with([
    'user info' => ['https://claude.ai@evil.example/callback', 'evil.example'],
    'a backslash before user info' => ['https://evil.example\\@claude.ai/callback', null],
]);

it('registers no redirect URI of a script or of a scheme the app does not list', function (string $redirect) {
    $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'a script' => 'javascript:alert(1)//claude.ai',
    'a scheme the app does not list' => 'cursor://claude.ai/callback',
]);

it('refuses, on a package path, a client whose redirect URI a browser reads with another host than the screen names', function () {
    $this->flow->register('https://evil.example\\@claude.ai/api/mcp/auth_callback', grants: ['authorization_code', 'refresh_token']);

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')
        ->assertForbidden()
        ->assertDontSee('you go to claude.ai');
});

it('widens no scope at a refresh, and takes no * on a package path', function () {
    $read = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme', 'actions:read');
    $refresh = $this->flow->tokens['refresh_token'];

    foreach (['actions:read actions:write', '*', 'actions:read mcp:use'] as $scope) {
        $this->flow->refresh(scope: $scope, refreshToken: $refresh)->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
    }

    expect($this->flow->tools($read, '/mcp/t/acme'))->toBe(['list-team-posts']);

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', '*')->assertStatus(400);
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', 'actions:read *')->assertStatus(400);
});

it('keeps a token left from an earlier grant to the scopes the person approved for the URL the connection moved to', function () {
    $acme = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/other', 'actions:read')->assertOk()->assertSee('Only in Other.');
    $this->flow->approve()->assertRedirect();
    $this->flow->token()->assertOk();

    // A refresh of Acme's pair that read its refresh token before the approval revoked it, and wrote its new pair
    // after, leaves a token of the earlier grant alive: the state it leaves, made here in one process.
    $id = json_decode(base64_decode(strtr(explode('.', $acme)[1], '-_', '+/')), true)['jti'];
    Passport::token()->newQuery()->whereKey($id)->update(['revoked' => false]);

    expect($this->flow->tools($acme, '/mcp/t/other'))->toBe(['list-team-posts'])
        ->and($this->flow->tools($acme, '/mcp/t/acme'))->toBe([])
        ->and($this->flow->tools($this->flow->accessToken(), '/mcp/t/other'))->toBe(['list-team-posts']);
})->group('database');

it('reads a Passport token on no Sanctum route, and a Sanctum token on no Passport route', function () {
    Route::post('only-sanctum', fn (): string => 'in')->middleware('auth:sanctum');
    Route::post('only-passport', fn (): string => 'in')->middleware('auth:api');
    $passport = $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');
    $sanctum = $this->alice->createToken('acme', ['actions:read', 'tenant:'.$this->acme->id])->plainTextToken;

    foreach ([['/only-sanctum', $passport], ['/only-passport', $sanctum]] as [$path, $token]) {
        app('auth')->forgetGuards();
        session()->flush();

        $this->postJson($path, [], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    expect($this->flow->tools($passport, '/mcp/t/acme'))->toBe(['create-team-post', 'list-team-posts'])
        ->and($this->flow->tools($sanctum, '/mcp/t/acme'))->toBe(['list-team-posts']);
});

it('reaches nothing through a guard the reader does not know, or a Passport guard that reads another user model', function (array $config) {
    $this->useOAuth(Flow::teams($config));
    Auth::viaRequest('custom', fn () => User::query()->first());
    $alice = User::factory()->create();
    $flow = new Flow($this);
    $token = $flow->connect($alice, 'http://localhost/mcp/actions', 'actions:read');

    expect($flow->tools($token, '/mcp/actions'))->toBe([]);
})->with([
    'a guard the reader does not know' => [[
        'auth.guards.custom' => ['driver' => 'custom'],
        'agentic-actions.mcp.middleware' => ['auth:custom,api', 'throttle:agentic-actions-mcp'],
    ]],
    'a Passport guard on another user model' => [[
        'auth.providers.people' => ['driver' => 'eloquent', 'model' => PassportUser::class],
        'auth.guards.people' => ['driver' => 'passport', 'provider' => 'people'],
        'agentic-actions.mcp.middleware' => ['auth:sanctum,people,api', 'throttle:agentic-actions-mcp'],
    ]],
]);

it('finishes a run queued before a revoke in its own tenant only, and refuses a run queued before the person left', function () {
    $this->useOAuth(Flow::teams(['agentic-actions.discovery.classes' => [QueueTeamPost::class]]));
    $alice = User::factory()->create();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $other = Team::factory()->create(['slug' => 'other']);
    $acme->users()->attach($alice);
    $other->users()->attach($alice);
    $flow = new Flow($this);
    $token = $flow->connect($alice, 'http://localhost/mcp/t/acme');
    Queued::onDatabase();
    $queue = fn (string $token, string $title) => $flow->mcp($token, '/mcp/t/acme', 'tools/call', ['name' => 'queue-team-post', 'arguments' => ['title' => $title]]);

    QueueTeamPost::$alsoIn = $other;
    $queue($token, 'Before the revoke')->assertOk()->assertJsonPath('result.isError', false);
    QueueTeamPost::$alsoIn = null;
    McpConnection::for($alice)->sole()->revoke();
    $queue($token, 'After the revoke')->assertUnauthorized();

    app('auth')->forgetGuards();
    Queued::work();
    Queued::work();

    expect(Post::query()->pluck('team_id', 'title')->all())->toBe(['Before the revoke' => $acme->id]);

    $again = $flow->connect($alice, 'http://localhost/mcp/t/acme');
    $queue($again, 'Before leaving')->assertOk()->assertJsonPath('result.isError', false);
    $acme->users()->detach($alice);

    app('auth')->forgetGuards();
    Queued::work();

    expect(Post::query()->pluck('title')->all())->toBe(['Before the revoke']);
});

it('names in the challenge and the metadata the host the request reached, and binds no URL of another host', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://alias.test/mcp/t/acme')->assertOk()->assertDontSee('Only in Acme.');
    $this->flow->approve()->assertRedirect();

    expect(McpConnection::query()->count())->toBe(0);

    // The test client sends a later relative URL to the host of the last request, so these come last.
    $this->flow->mcp(null, 'http://alias.test/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://alias.test/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');

    $this->getJson('http://alias.test/.well-known/oauth-protected-resource/mcp/t/acme')
        ->assertOk()
        ->assertJsonPath('resource', 'http://alias.test/mcp/t/acme')
        ->assertJsonPath('scopes_supported', ['actions:read', 'actions:write']);
});

it('answers refusals, errors and challenges with nothing of the package\'s internals', function () {
    config(['app.debug' => false]);
    $this->flow->register();

    $bodies = [
        (string) $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', method: 'plain')->assertStatus(400)->getContent(),
        (string) $this->flow->authorize($this->alice, 'http://localhost/mcp/t/unknown')->assertForbidden()->assertSee('This app cannot be connected to this address.')->getContent(),
        (string) $this->flow->mcp('not-a-token', '/mcp/t/acme')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.'])->getContent(),
    ];

    foreach ($bodies as $body) {
        expect($body)->not->toContain('AgenticActions')->not->toContain('Exception')->not->toContain('agentic_mcp_connections')->not->toContain('oauth_');
    }

    $odd = (string) $this->flow->mcp(null, '/mcp/t/a%22b%0D%0Ac')->assertUnauthorized()->headers->get('WWW-Authenticate');

    expect($this->flow->mcp('not-a-token', '/mcp/t/acme')->headers->get('WWW-Authenticate'))
        ->toBe('Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"')
        ->and($odd)->toStartWith('Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/a%')
        ->toEndWith('", scope="actions:read actions:write"')
        ->and(substr_count($odd, '"'))->toBe(6)
        ->and(preg_match('/[\r\n]/', $odd))->toBe(0);
});

it('throttles registration and the token endpoint in the documented setup', function () {
    foreach (range(1, 60) as $n) {
        $this->postJson('/oauth/register', ['client_name' => "Client {$n}", 'redirect_uris' => ['http://127.0.0.1:8765/callback']])->assertCreated();
    }

    $this->postJson('/oauth/register', ['client_name' => 'One more', 'redirect_uris' => ['http://127.0.0.1:8765/callback']])->assertTooManyRequests();

    Cache::flush();
    $unknown = ['grant_type' => 'refresh_token', 'client_id' => (string) Str::uuid(), 'refresh_token' => 'y'];

    foreach (range(1, 60) as $n) {
        $this->post('/oauth/token', $unknown)->assertStatus(401);
    }

    $this->post('/oauth/token', $unknown)->assertTooManyRequests();
});
