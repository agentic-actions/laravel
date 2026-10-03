<?php

use AgenticActions\OAuth\BindConsent;
use AgenticActions\OAuth\Consent;
use AgenticActions\OAuth\McpConnection;
use AgenticActions\OAuth\OAuthServiceProvider;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The consent screen. Every package URL shows it, it names the client, where the code goes, the tenant and the
 * abilities, it cannot be framed, and only an approval of the screen the person saw records a connection.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    $this->alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $this->acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->other = Team::factory()->create(['name' => 'Other', 'slug' => 'other']);
    $this->acme->users()->attach([$this->alice->id, $this->bob->id]);

    $this->flow = new Flow($this);
});

/**
 * The consent Passport's parameters build, for a client with these redirect URIs and a request with this query.
 *
 * @param  list<string>  $redirects
 * @param  array<string, string>  $query
 * @param  list<string>  $scopes
 * @return array<string, mixed>
 */
function consentFor(array $redirects, array $query = [], array $scopes = ['actions:read'], ?Team $tenant = null, string $client = 'Claude'): array
{
    $model = (new Client)->forceFill(['id' => 'client-1', 'name' => $client, 'redirect_uris' => $redirects]);
    $request = Request::create('/oauth/authorize', 'GET', $query);
    $request->attributes->set(BindConsent::ATTRIBUTE, ['route' => 'agentic-actions.mcp.tenant', 'tenant' => $tenant]);

    return Consent::from([
        'client' => $model,
        'user' => test()->alice,
        'scopes' => Passport::scopesFor($scopes),
        'request' => $request,
        'authToken' => 'token-1',
    ])->toArray();
}

it('builds what the person approves from Passport\'s parameters', function () {
    config(['app.name' => 'Blog']);
    $tenant = (new Team)->forceFill(['id' => 9, 'name' => "Ac\u{202E}me\u{200F}", 'slug' => 'acme']);

    $consent = consentFor(['https://claude.ai/api/mcp/auth_callback'], ['redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'state' => 'st'], ['actions:read', 'actions:write', 'mcp:use'], $tenant, "Cl\u{2067}au\u{061C}de");

    expect($consent)->toBe([
        'app' => 'Blog',
        'client' => 'Cl au de',
        'redirect' => ['host' => 'claude.ai', 'local' => false],
        'tenant' => 'Ac me',
        'abilities' => ['Read what you can see', 'Create and change what you can change', 'Use MCP server'],
        'person' => 'alice@example.com',
        'approve' => ['url' => 'http://localhost/oauth/authorize', 'method' => 'POST', 'fields' => ['_token' => csrf_token(), 'state' => 'st', 'client_id' => 'client-1', 'auth_token' => 'token-1']],
        'deny' => ['url' => 'http://localhost/oauth/authorize', 'method' => 'POST', 'fields' => ['_token' => csrf_token(), 'state' => 'st', 'client_id' => 'client-1', 'auth_token' => 'token-1', '_method' => 'DELETE']],
    ]);
});

it('shows where the code goes: the request\'s redirect, else the one registered, and this device for loopback and custom schemes', function () {
    expect(consentFor(['https://chatgpt.com/connector/oauth/x'])['redirect'])->toBe(['host' => 'chatgpt.com', 'local' => false])
        ->and(consentFor(['https://a.example/cb', 'http://127.0.0.1/cb'], ['redirect_uri' => 'http://127.0.0.1:33418/cb'])['redirect'])->toBe(['host' => '127.0.0.1', 'local' => true])
        ->and(consentFor(['http://localhost:8765/callback'])['redirect'])->toBe(['host' => 'localhost', 'local' => true])
        ->and(consentFor(['cursor://claude.ai/cb'])['redirect'])->toBe(['host' => 'cursor://', 'local' => true]);
});

it('names the tenant by its route key when it has no name, and the abilities in the request\'s locale', function () {
    $tenant = (new Team)->forceFill(['id' => 9, 'slug' => "acme\u{200E}"]);

    app()->setLocale('ar');

    expect(consentFor(['https://claude.ai/cb'], [], ['actions:read', 'actions:write'], $tenant))
        ->tenant->toBe('acme')
        ->abilities->toBe(['قراءة ما يمكنك رؤيته', 'إنشاء وتعديل ما يمكنك تعديله']);
});

it('renders the default view in English and in a right-to-left language, with both forms and their fields', function () {
    $this->flow->register('https://claude.ai/api/mcp/auth_callback');

    $page = $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $token = session('authToken');

    $page->assertSee('<html lang="en" dir="ltr">', false)
        ->assertSee('Continue only if you started connecting Claude yourself, just now.')
        ->assertSee('Signed in as alice@example.com')
        ->assertSee('<input type="hidden" name="auth_token" value="'.$token.'">', false)
        ->assertSee('<input type="hidden" name="_method" value="DELETE">', false)
        ->assertSee('<input type="hidden" name="_token" value="'.csrf_token().'">', false)
        ->assertSee('<form method="POST" action="http://localhost/oauth/authorize">', false);

    expect(substr_count($page->getContent(), '<form method="POST"'))->toBe(2);

    app()->setLocale('ar');

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('ربط Claude بـ Laravel؟')
        ->assertSee('في Acme فقط.');

    app()->setLocale('he');

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()->assertSee('<html lang="he" dir="rtl">', false);
});

it('answers an Inertia visit, as a sign-in form redirects back, with a full page load of its page', function () {
    $this->flow->register();

    $visit = $this->withHeader('X-Inertia', 'true')->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')
        ->assertStatus(409)
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($visit->headers->get('X-Inertia-Location'))->toStartWith('http://localhost/oauth/authorize?')
        ->toContain('resource=http%3A%2F%2Flocalhost%2Fmcp%2Ft%2Facme')
        ->and($visit->getContent())->toBe('')
        ->and(session(BindConsent::SESSION))->toBeNull();

    $this->flushHeaders()->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertSee('Continue only if you started connecting Claude yourself, just now.');

    expect(session(BindConsent::SESSION))->not->toBeNull();
});

it('sends the frame-denying headers on every answer it passes, the package\'s page and the app\'s own alike', function () {
    $this->flow->register();

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");

    $this->flow->authorize($this->alice, null)->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");

    Passport::authorizationView(fn (array $parameters) => response('Our own page'));

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()
        ->assertSee('Our own page')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});

it('sends a guest to login, and the intended URL keeps the resource', function () {
    $this->flow->register();

    $this->get('/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $this->flow->client, 'redirect_uri' => $this->flow->redirect, 'scope' => 'actions:read', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'resource' => 'http://localhost/mcp/t/acme']))
        ->assertRedirect('http://localhost/login');

    expect(session('url.intended'))->toContain('resource=http%3A%2F%2Flocalhost%2Fmcp%2Ft%2Facme');
});

it('answers an unknown team and a team the person is not in with the same 403, before Passport\'s view', function () {
    $this->flow->register();

    $unknown = $this->flow->authorize($this->alice, 'http://localhost/mcp/t/nowhere')->assertForbidden();
    $foreign = $this->flow->authorize($this->alice, 'http://localhost/mcp/t/other')->assertForbidden();

    expect($unknown->getContent())->toBe($foreign->getContent())
        ->and($foreign->getContent())->toContain('This app cannot be connected to this address. Check the URL you entered.')
        ->and(session('authToken'))->toBeNull()
        ->and(session(BindConsent::SESSION))->toBeNull();
});

it('answers 400 on a package path without S256 or with scopes the path does not take, and leaves other URLs to Passport', function () {
    Passport::tokensCan([...Passport::$scopes, 'admin' => 'Administer']);
    $this->flow->register();

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', method: 'plain')->assertStatus(400);
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', method: null)->assertStatus(400);
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', scope: null)->assertStatus(400);
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', scope: 'actions:read actions:write admin')->assertStatus(400);

    expect(session('authToken'))->toBeNull();

    $this->flow->authorize($this->alice, null, method: 'plain')->assertOk();
    $this->flow->authorize($this->alice, null, scope: 'actions:read admin')->assertOk();
});

it('refuses a client with another grant type, or a redirect URI outside printable ASCII, on a package path', function (string $redirect, ?array $grants) {
    $this->flow->register($redirect, $grants);

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')
        ->assertForbidden()
        ->assertSee('This app cannot be connected to this address.');

    expect(session('authToken'))->toBeNull();
})->with([
    'the device grant' => ['https://claude.ai/api/mcp/auth_callback', ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code']],
    'a Cyrillic letter in the host' => ["https://cl\u{0430}ude.ai/api/mcp/auth_callback", null],
]);

it('shows the screen to a returning client whose token covers the scopes, and to one that sent prompt=none', function () {
    $this->flow->connect($this->alice, 'http://localhost/mcp/t/acme');

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk()->assertSee('Only in Acme.');
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme', extra: ['prompt' => 'none'])->assertOk()->assertSee('Only in Acme.');
});

it('redirects a denial with access_denied and records nothing', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();

    $denied = $this->flow->deny();

    expect($this->flow->redirected($denied))->toMatchArray(['error' => 'access_denied', 'state' => 'st123'])
        ->and(McpConnection::query()->count())->toBe(0);
});

it('refuses an approval that does not match the screen, or by another person, and records nothing', function () {
    $this->flow->register();
    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();

    $this->flow->approve(['auth_token' => 'forged'])->assertForbidden();

    expect($this->flow->code)->toBeNull();

    $this->flow->authorize($this->alice, 'http://localhost/mcp/t/acme')->assertOk();
    $this->actingAs($this->bob, 'web');

    $this->flow->approve()->assertForbidden();

    expect($this->flow->code)->toBeNull()
        ->and(McpConnection::query()->count())->toBe(0);
});

it('leaves an authorization without a resource, from a client the person has not connected, to Passport', function () {
    $this->flow->register();

    $this->flow->authorize($this->alice, null, extra: ['prompt' => 'select_account'])->assertOk();

    $request = app('request');

    expect($request->query('prompt'))->toBe('select_account')
        ->and($request->attributes->has(BindConsent::ATTRIBUTE))->toBeFalse()
        ->and(session(BindConsent::SESSION))->toBeNull();
});

it('binds its consent screen when the app set none, and keeps a view the app set', function () {
    expect(app(AuthorizationViewResponse::class))->toBeInstanceOf(AuthorizationViewResponse::class);

    Passport::authorizationView(fn (array $parameters) => response('Mine'));
    $mine = app(AuthorizationViewResponse::class);

    (new OAuthServiceProvider(app()))->boot();

    expect(app(AuthorizationViewResponse::class))->toBe($mine);
});
