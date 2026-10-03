<?php

namespace Tests\Fixtures\OAuth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Tenancy\TeamMembership;
use Tests\TestCase;
use Workbench\App\Models\Team;

/**
 * One OAuth client's way through the flow, shaped as Claude runs it, in one process: registration, the authorization
 * request with resource and S256, the screen, approval, the token request with the verifier, refresh, and MCP calls with
 * the bearer token.
 */
final class Flow
{
    /**
     * The client id registration gave.
     */
    public string $client = '';

    /**
     * The redirect URI the client registered and uses.
     */
    public string $redirect = '';

    /**
     * The PKCE verifier of the last authorization request.
     */
    public string $verifier = '';

    /**
     * The code the last approval redirected with, or null.
     */
    public ?string $code = null;

    /**
     * The last token pair: access_token, refresh_token, and the rest of the answer.
     *
     * @var array<string, mixed>
     */
    public array $tokens = [];

    /**
     * The OAuth tests' application: the fixtures in Actions/, the workbench's teams as tenants routed by slug, and the
     * tenant path mcp/t/{team}; pass it to useOAuth(), with any values over it.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function teams(array $config = []): array
    {
        return [
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'agentic-actions.discovery.paths' => [__DIR__.'/Actions'],
            'agentic-actions.tenant.model' => Team::class,
            'agentic-actions.tenant.parameter' => 'team',
            'agentic-actions.tenant.membership' => TeamMembership::class,
            'agentic-actions.mcp.tenant_path' => 'mcp/t/{team}',
            ...$config,
        ];
    }

    /**
     * Start a flow in a test.
     */
    public function __construct(private readonly TestCase $test) {}

    /**
     * Register through laravel/mcp's dynamic client registration, or, with other grant types or a redirect URI
     * registration refuses, through Passport's client repository.
     *
     * @param  list<string>|null  $grants
     */
    public function register(string $redirect = 'http://127.0.0.1:8765/callback', ?array $grants = null, string $name = 'Claude'): self
    {
        $this->redirect = $redirect;

        if ($grants === null && in_array(parse_url($redirect, PHP_URL_SCHEME), ['http', 'https'], true) && preg_match('/[^\x21-\x7E]/', $redirect) !== 1) {
            $this->client = (string) $this->test->postJson('/oauth/register', [
                'client_name' => $name,
                'redirect_uris' => [$redirect],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'token_endpoint_auth_method' => 'none',
            ])->assertCreated()->json('client_id');

            return $this;
        }

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, [$redirect], confidential: false);

        if ($grants !== null) {
            $client->forceFill(['grant_types' => $grants])->save();
        }

        $this->client = (string) $client->getKey();

        return $this;
    }

    /**
     * The authorization request, signed in as the person; the extra parameters replace the flow's.
     *
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    public function authorize(Authenticatable $person, ?string $resource, ?string $scope = 'actions:read actions:write', ?string $method = 'S256', array $extra = []): TestResponse
    {
        $this->verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');

        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $this->client,
            'redirect_uri' => $this->redirect,
            'scope' => $scope,
            'state' => 'st123',
            'code_challenge' => $challenge,
            'code_challenge_method' => $method,
            'resource' => $resource,
        ], fn (?string $value): bool => $value !== null);
        $query = [...$query, ...$extra];

        // Passport's controller keeps the guard it was built with, and a test's requests share one application.
        Route::getRoutes()->getByName('passport.authorizations.authorize')?->flushController();

        return $this->test->actingAs($person, 'web')->get('/oauth/authorize?'.http_build_query($query));
    }

    /**
     * Approve the screen, as the person who is signed in; the code the answer redirects with is kept.
     *
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    public function approve(array $fields = []): TestResponse
    {
        $response = $this->test->post('/oauth/authorize', [
            'state' => 'st123',
            'client_id' => $this->client,
            'auth_token' => session('authToken'),
            ...$fields,
        ]);

        $this->code = $this->redirected($response)['code'] ?? null;

        return $response;
    }

    /**
     * Deny the screen.
     *
     * @return TestResponse<Response>
     */
    public function deny(): TestResponse
    {
        return $this->test->delete('/oauth/authorize', ['state' => 'st123', 'client_id' => $this->client, 'auth_token' => session('authToken')]);
    }

    /**
     * The query the answer redirected to the client with.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    public function redirected(TestResponse $response): array
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return array_map(strval(...), $query);
    }

    /**
     * Exchange a code for tokens, with the verifier and the resource; the pair is kept when one is issued.
     *
     * @param  array<string, string>  $fields
     * @return TestResponse<Response>
     */
    public function token(?string $resource = null, array $fields = []): TestResponse
    {
        return $this->keep($this->test->post('/oauth/token', array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => $this->client,
            'redirect_uri' => $this->redirect,
            'code' => $this->code,
            'code_verifier' => $this->verifier,
            'resource' => $resource,
            ...$fields,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Refresh the pair, naming a resource and a scope or neither.
     *
     * @return TestResponse<Response>
     */
    public function refresh(?string $resource = null, ?string $scope = null, ?string $refreshToken = null): TestResponse
    {
        return $this->keep($this->test->post('/oauth/token', array_filter([
            'grant_type' => 'refresh_token',
            'client_id' => $this->client,
            'refresh_token' => $refreshToken ?? $this->tokens['refresh_token'] ?? null,
            'resource' => $resource,
            'scope' => $scope,
        ], fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Register, authorize for a resource, approve and take the token: the access token.
     */
    public function connect(Authenticatable $person, ?string $resource, string $scope = 'actions:read actions:write'): string
    {
        if ($this->client === '') {
            $this->register();
        }

        $this->authorize($person, $resource, $scope)->assertOk();
        $this->approve()->assertRedirect();
        $this->token($resource)->assertOk();

        return $this->accessToken();
    }

    /**
     * The access token of the last pair.
     */
    public function accessToken(): string
    {
        return (string) ($this->tokens['access_token'] ?? '');
    }

    /**
     * One MCP request with a bearer token, as a fresh request: the guards an earlier request resolved and the web
     * session of actingAs() are forgotten first, so the token is really read.
     *
     * @param  array<string, mixed>  $params
     * @return TestResponse<Response>
     */
    public function mcp(?string $token, string $path, string $method = 'tools/list', array $params = []): TestResponse
    {
        app('auth')->forgetGuards();
        session()->flush();

        return $this->test->postJson($path, JsonRpc::legacy($method, $params), [
            'Accept' => 'application/json, text/event-stream',
            ...($token === null ? [] : ['Authorization' => "Bearer {$token}"]),
        ]);
    }

    /**
     * The tool names a token lists on a path.
     *
     * @return list<string>
     */
    public function tools(?string $token, string $path): array
    {
        return $this->mcp($token, $path)->assertOk()->json('result.tools.*.name') ?? [];
    }

    /**
     * Keep the pair a token answer issued.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        if ($response->getStatusCode() === 200) {
            $this->tokens = (array) $response->json();
        }

        return $response;
    }
}
