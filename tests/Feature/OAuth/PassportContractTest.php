<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Models\User;

/*
 * The laravel/passport and laravel/mcp members 0.7 relies on. A release that changes one fails
 * here before a connector notices.
 */

beforeEach(function () {
    $this->skipUnlessPassport();
});

it('keeps Passport\'s route names and laravel/mcp\'s metadata route and scope', function () {
    $this->useOAuth(Flow::teams());

    foreach (['passport.authorizations.authorize', 'passport.authorizations.approve', 'passport.authorizations.deny', 'passport.token', 'mcp.oauth.protected-resource.nested'] as $name) {
        expect(Route::has($name))->toBeTrue("[{$name}] is not a route.");
    }

    expect(Route::getRoutes()->getByName('passport.authorizations.authorize')->methods())->toContain('GET')
        ->and(Route::getRoutes()->getByName('passport.authorizations.approve')->methods())->toBe(['POST'])
        ->and(Route::getRoutes()->getByName('passport.authorizations.deny')->methods())->toBe(['DELETE'])
        ->and(Registrar::OAUTH_SCOPE)->toBe('mcp:use');
});

it('runs laravel/mcp\'s header middleware globally as well', function () {
    expect(app(Kernel::class)->hasMiddleware(AddWwwAuthenticateHeader::class))->toBeTrue();
});

it('keeps an access token\'s four attributes in toArray()', function () {
    $token = new AccessToken(['oauth_access_token_id' => 'a', 'oauth_client_id' => 'c', 'oauth_user_id' => '1', 'oauth_scopes' => ['actions:read']]);

    expect($token->toArray())->toBe(['oauth_access_token_id' => 'a', 'oauth_client_id' => 'c', 'oauth_user_id' => '1', 'oauth_scopes' => ['actions:read']]);
});

it('answers a Responsable from the authorization view closure, and binds the view only through authorizationView()', function () {
    expect(app()->bound(AuthorizationViewResponse::class))->toBeFalse();

    Passport::authorizationView(fn (array $parameters): Responsable => new class implements Responsable
    {
        public function toResponse($request): Response
        {
            return response('From a Responsable');
        }
    });

    $view = app(AuthorizationViewResponse::class);

    expect($view)->toBeInstanceOf(SimpleViewResponse::class)
        ->and($view->withParameters([])->toResponse(request())->getContent())->toBe('From a Responsable');
});

it('keeps Passport\'s scope list public and static, and the token lifetime getter', function () {
    expect((new ReflectionProperty(Passport::class, 'scopes'))->isStatic())->toBeTrue()
        ->and((new ReflectionProperty(Passport::class, 'scopes'))->isPublic())->toBeTrue()
        ->and(Passport::tokensExpireIn())->toBeInstanceOf(DateInterval::class)
        ->and(Passport::tokensExpireIn()->y)->toBe(1);
});

it('reads a client\'s grant types and redirect URIs as arrays', function () {
    $client = (new Client)->forceFill(['redirect_uris' => ['https://claude.ai/cb'], 'grant_types' => ['authorization_code', 'refresh_token']]);

    expect($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and($client->redirect_uris)->toBe(['https://claude.ai/cb']);
});

it('reads prompt from the request, so a prompt the package sets shows the screen to a returning client', function () {
    $this->useOAuth(Flow::teams());
    $alice = User::factory()->create();
    $flow = new Flow($this);

    $flow->connect($alice, null);

    $flow->authorize($alice, null)->assertRedirect();
    $flow->authorize($alice, null, extra: ['prompt' => 'consent'])->assertOk();
});
