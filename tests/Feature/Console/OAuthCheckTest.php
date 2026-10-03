<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Support\PackageStatus;
use Carbon\CarbonInterval;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Tests\Fixtures\Checks\HeaderTokenReader;
use Tests\Fixtures\OAuth\Flow;

/*
 * actions:check's OAuth row, its Tables line and Passport's guards in Token routes. Each test boots with
 * OAuth on, every step taken and one-hour tokens, then undoes one step.
 */

beforeEach(function () {
    $this->useOAuth(Flow::teams());

    Passport::tokensExpireIn(CarbonInterval::hour());
});

afterEach(function () {
    File::delete(Snapshot::path());
});

/**
 * The findings of these rows, as [level, row, message] triples.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function oauthFindings(string ...$rows): array
{
    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => in_array($finding->row, $rows ?: ['OAuth'], true)),
    ));
}

/**
 * Replace the routes with these first and the app's after them, or without the ones named.
 *
 * @param  list<string>  $without
 */
function reorderRoutes(Closure $first, array $without = []): void
{
    $routes = app('router')->getRoutes()->getRoutes();
    app('router')->setRoutes(new RouteCollection);
    $first();

    foreach ($routes as $route) {
        if (! in_array($route->getName(), $without, true)) {
            app('router')->getRoutes()->add($route);
        }
    }

    app('router')->getRoutes()->refreshNameLookups();
}

it('reports nothing when every step is taken', function () {
    expect(oauthFindings('OAuth', 'Tables', 'Token routes'))->toBe([]);
});

it('fails an app without Passport\'s keys', function () {
    config(['passport.private_key' => null]);
    Passport::loadKeysFrom(sys_get_temp_dir().'/agentic-actions-no-keys-'.getmypid());

    expect(oauthFindings())->toBe([
        ['fail', 'OAuth', 'Passport has no keys, so no client can get a token. Run php artisan passport:keys.'],
    ]);
});

it('fails a Passport guard listed before Sanctum\'s', function () {
    config(['agentic-actions.mcp.middleware' => ['auth:api,sanctum', 'throttle:agentic-actions-mcp']]);

    expect(oauthFindings())->toBe([
        ['fail', 'OAuth', 'agentic-actions.mcp.middleware tries [api] before [sanctum], so every Sanctum token answers 401. List Sanctum first: auth:sanctum,api.'],
    ]);
});

it('fails a token reader of the app\'s own beside OAuth connections', function () {
    app()->singleton(ReadsTokenGrants::class, HeaderTokenReader::class);

    expect(oauthFindings())->toBe([
        ['fail', 'OAuth', '['.HeaderTokenReader::class.'] replaces the package\'s token reader, which keeps each OAuth connection to the URL and tenant the person approved. Remove that binding, or take the Passport guard out of agentic-actions.mcp.middleware.'],
    ]);
});

it('fails an app route that answers a package path\'s metadata first, naming it', function () {
    reorderRoutes(fn () => Route::get('.well-known/oauth-protected-resource/mcp/t/{id}', fn () => ['scopes_supported' => []]));

    expect(oauthFindings())->toBe([
        ['fail', 'OAuth', 'The route [GET .well-known/oauth-protected-resource/mcp/t/{id}] answers the resource metadata of [mcp/t/{team}] before the package, so clients never learn its scopes. Move that route.'],
    ]);
});

it('warns about Mcp::oauthRoutes() with no Passport guard in mcp.middleware, and about the reverse', function () {
    config(['agentic-actions.mcp.middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp']]);

    expect(oauthFindings())->toBe([
        ['warn', 'OAuth', 'Mcp::oauthRoutes() lets clients sign in, but agentic-actions.mcp.middleware names no Passport guard, so the package\'s paths take no OAuth token. Add it after Sanctum\'s: auth:sanctum,api.'],
    ]);

    $this->useOAuth(Flow::teams(), routes: null);
    Passport::tokensExpireIn(CarbonInterval::hour());

    expect(oauthFindings())->toBe([
        ['warn', 'OAuth', "The MCP paths read Passport tokens, but no client can discover how to sign in. In routes/ai.php: Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());"],
    ]);
});

it('warns about an app without a login route', function () {
    reorderRoutes(fn () => null, without: ['login']);

    expect(oauthFindings())->toBe([
        ['warn', 'OAuth', 'No route is named login, so a signed-out person has nowhere to sign in before approving a client.'],
    ]);
});

it('warns about access tokens that last more than a day, and not about an hour', function () {
    Passport::$tokensExpireIn = null;

    expect(oauthFindings())->toBe([
        ['warn', 'OAuth', 'Passport\'s access tokens last [1 year], so a leaked connector token works that long. In AppServiceProvider::boot(): Passport::tokensExpireIn(CarbonInterval::hour()); it applies to every Passport client.'],
    ]);

    Passport::tokensExpireIn(CarbonInterval::hour());

    expect(oauthFindings())->toBe([]);
});

it('warns about redirect domains that accept any site in production, not in local', function () {
    config(['mcp.redirect_domains' => ['*']]);

    expect(oauthFindings())->toBe([]);

    app()->detectEnvironment(fn () => 'production');

    expect(oauthFindings())->toBe([
        ['warn', 'OAuth', 'config/mcp.php redirect_domains accepts any site, so any site can register as a client. List the ones you allow (docs/mcp.md#harden-the-oauth-setup).'],
    ]);

    config(['mcp.redirect_domains' => ['https://claude.ai/', 'http://localhost']]);

    expect(oauthFindings())->toBe([]);
});

it('reports nothing without Passport, or with Passport and no Passport guard or OAuth routes', function (array $packages, array $config) {
    $this->useOAuth(Flow::teams($config), $packages, routes: null);

    expect(oauthFindings('OAuth', 'Tables'))->toBe([]);
})->with([
    'without Passport' => [['laravel/passport' => PackageStatus::Missing], []],
    'no Passport guard' => [[], ['agentic-actions.mcp.middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp']]],
]);

it('fails the Tables row without the connections table', function () {
    Schema::drop('agentic_mcp_connections');

    expect(oauthFindings('Tables'))->toBe([
        ['fail', 'Tables', 'MCP clients sign in with OAuth, but the database has no [agentic_mcp_connections] table: run php artisan actions:install --mcp'],
    ]);
});

it('takes Passport\'s scope middleware as checking an ability, and fails an unscoped route on any Passport guard while registration is open', function () {
    app('router')->aliasMiddleware('scopes', CheckToken::class);
    config(['auth.guards.partner' => ['driver' => 'passport', 'provider' => 'users']]);

    Route::middleware(['auth:api', 'scopes:profile'])->get('api/profile', fn () => 'me');
    Route::middleware(['auth:api', CheckToken::class.':profile'])->get('api/me', fn () => 'me');
    Route::middleware('auth:api')->get('api/user', fn () => 'me');
    Route::middleware('auth:partner')->get('partner/orders', fn () => 'orders');

    expect(oauthFindings('Token routes'))->toBe([
        ['fail', 'Token routes', 'The route [GET|HEAD api/user] authenticates with the MCP guard [api] and checks no ability, so an MCP token reaches it too. Add scope:… or scopes:… middleware, or move it off that guard.'],
        ['fail', 'Token routes', 'The route [GET|HEAD partner/orders] authenticates with the MCP guard [partner] and checks no ability, so an MCP token reaches it too. Add scope:… or scopes:… middleware, or move it off that guard.'],
    ]);
});
