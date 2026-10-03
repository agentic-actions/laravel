<?php

use AgenticActions\OAuth\BindConsent;
use AgenticActions\OAuth\Discovery;
use AgenticActions\Support\PackageStatus;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use Tests\Fixtures\OAuth\Flow;

/*
 * Until a Passport guard is named in mcp.middleware, with Passport installed or not, nothing of OAuth changes: no
 * scopes, no view, no middleware on Passport's routes, and the 401 header of 0.6.
 */

/**
 * Assert nothing of the package's OAuth is registered.
 */
function assertNoPackageOAuth(): void
{
    expect(Passport::$scopes)->not->toHaveKeys(['actions:read', 'actions:write'])
        ->and(view()->exists('agentic-actions::consent'))->toBeFalse()
        ->and(app()->bound(AuthorizationViewResponse::class))->toBeFalse();

    foreach (['passport.authorizations.authorize', 'passport.authorizations.approve'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->middleware())->not->toContain(BindConsent::class);
    }

    expect(Route::getRoutes()->getByName(Discovery::METADATA)?->middleware() ?? [])->not->toContain(Discovery::class);

    foreach (['agentic-actions.mcp', 'agentic-actions.mcp.tenant'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route->middleware())->toContain(AddWwwAuthenticateHeader::class)->not->toContain(Discovery::class)
            ->and($route->excludedMiddleware())->toBe([]);
    }
}

it('changes nothing with laravel/passport missing, with and without Mcp::oauthRoutes()', function (?string $routes, string $header) {
    $this->useOAuth(Flow::teams(), ['laravel/passport' => PackageStatus::Missing], $routes);

    assertNoPackageOAuth();

    (new Flow($this))->mcp(null, '/mcp/t/acme')->assertUnauthorized()->assertHeader('WWW-Authenticate', $header);
})->with([
    'with Mcp::oauthRoutes()' => ['boot', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme"'],
    'without' => [null, 'Bearer realm="mcp", error="invalid_token"'],
]);

it('changes nothing with Passport installed and no Passport guard in mcp.middleware, with and without Mcp::oauthRoutes()', function (?string $routes, string $header) {
    $this->useOAuth(Flow::teams(['agentic-actions.mcp.middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp']]), [], $routes);

    assertNoPackageOAuth();

    (new Flow($this))->mcp(null, '/mcp/t/acme')->assertUnauthorized()->assertHeader('WWW-Authenticate', $header);

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/acme')->assertStatus($routes === null ? 404 : 200);
})->with([
    'with Mcp::oauthRoutes()' => ['boot', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme"'],
    'without' => [null, 'Bearer realm="mcp", error="invalid_token"'],
]);
