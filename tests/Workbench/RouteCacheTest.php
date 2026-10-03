<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Manifest;
use AgenticActions\OAuth\BindConsent;
use AgenticActions\OAuth\Discovery;
use AgenticActions\OAuth\McpConnection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Tests\Fixtures\OAuth\Flow;
use Workbench\App\Actions\CreatePost;
use Workbench\App\Actions\ListTeamPosts;
use Workbench\App\Actions\PublishPost;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

use function Orchestra\Testbench\remote;
use function Orchestra\Testbench\workbench_path;

/*
 * The generated routes through Laravel's route cache, with the workbench's real route files. Every cache goes to a
 * scratch file, never into the Testbench skeleton, where every later test would boot on it.
 */

beforeEach(function () {
    $scratch = sys_get_temp_dir().'/agentic-actions-optimize-'.getmypid();

    $this->scratch = $scratch;
    $this->manifest = Manifest::path($this->app);
    $this->caches = [
        'APP_EVENTS_CACHE' => "{$scratch}/events.php",
        'APP_ROUTES_CACHE' => "{$scratch}/routes.php",
        'APP_SERVICES_CACHE' => "{$scratch}/services.php",
        'APP_PACKAGES_CACHE' => "{$scratch}/packages.php",
    ];

    File::deleteDirectory($scratch);
    File::ensureDirectoryExists("{$scratch}/views");
    File::delete($this->manifest);
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
    File::delete($this->manifest);
});

/**
 * Compile the router's routes and load them back exactly as a route cache file does, then mark the routes cached,
 * which is what the application memoizes once it has seen a route cache file.
 */
function loadRoutesThroughTheCache(string $path): void
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $route->prepareForSerialization();
    }

    File::put($path, '<?php app(\'router\')->setCompiledRoutes('.var_export(Route::getRoutes()->compile(), true).');');

    require $path;

    app()->instance('routes.cached', true);
}

/**
 * Register the workbench's route files again, as Testbench does at boot, after a config change.
 */
function registerWorkbenchRoutes(): void
{
    Route::setRoutes(new RouteCollection);

    Route::middleware('api')->group(workbench_path(['routes', 'api.php']));
    Route::middleware('web')->group(workbench_path(['routes', 'web.php']));
}

it('compiles the generated routes and the MCP mount into the route cache during optimize, and writes the manifest after it', function () {
    // A separate process, as on a server. Testbench never reads a cached configuration, so a config:cache inside the
    // same optimize run would hand route:cache an application without any package's defaults: config is left out.
    $env = [
        'APP_ENV' => 'local',
        'CACHE_STORE' => 'array',
        'VIEW_COMPILED_PATH' => "{$this->scratch}/views",
        ...$this->caches,
    ];

    remote(['optimize', '--except=config'], $env)->mustRun();

    $routes = File::get($this->caches['APP_ROUTES_CACHE']);

    expect($routes)
        ->toContain("'actions.create-post'")
        ->toContain("'api.actions.create-post'")
        ->toContain("'teams.actions.list-team-posts'")
        ->toContain("'teams.actions.publish-post'")
        ->toContain("'teams.actions.draft-team-post'")
        ->toContain("'actions._views'")
        ->not->toContain("'api.actions.list-team-posts'")
        ->toContain("'agentic-actions.mcp'")
        ->toContain("'agentic-actions.mcp.tenant'")
        ->toContain("'agentic_mcp' => true")
        ->and($this->manifest)->toBeFile()
        ->and(filemtime($this->manifest))->toBeGreaterThanOrEqual(filemtime($this->caches['APP_ROUTES_CACHE']));

    $manifest = require $this->manifest;

    expect($manifest['version'])->toBe(ActionRegistry::VERSION)
        ->and(array_keys($manifest['actions']))->toBe(['create-post', 'delete-post', 'draft-team-post', 'import-posts', 'list-team-posts', 'post-stats', 'posts', 'publish-post']);

    remote(['optimize:clear'], $env)->mustRun();

    expect($this->manifest)->not->toBeFile()
        ->and($this->caches['APP_ROUTES_CACHE'])->not->toBeFile()
        ->and($this->caches['APP_EVENTS_CACHE'])->not->toBeFile();
});

it('fails the Routes row once a config change closes a cached action\'s web surface, until the routes are cached again', function () {
    loadRoutesThroughTheCache($this->caches['APP_ROUTES_CACHE']);

    $this->artisan('actions:check')->doesntExpectOutputToContain('[Routes]')->assertSuccessful();

    config([
        'agentic-actions.discovery.paths' => [],
        'agentic-actions.discovery.classes' => [CreatePost::class, ListTeamPosts::class],
    ]);

    $this->refreshActions();

    $this->artisan('actions:check')
        ->expectsOutputToContain('[Routes] The cached route [teams.actions.publish-post] serves '.PublishPost::class.', which the actions on disk no longer open on the web: run php artisan route:cache again.')
        ->assertFailed();

    registerWorkbenchRoutes();
    loadRoutesThroughTheCache($this->caches['APP_ROUTES_CACHE']);

    expect(Route::getRoutes()->getByName('teams.actions.publish-post'))->toBeNull()
        ->and(Route::getRoutes()->getByName('teams.actions.list-team-posts'))->not->toBeNull();

    // The snapshot still lists publish-post, so only the Routes row is expected to pass now.
    $this->artisan('actions:check')->doesntExpectOutputToContain('[Routes]')->assertFailed();
});

it('keeps the OAuth challenge, the metadata and both consent middleware through the route cache', function () {
    $this->useOAuth();
    $alice = User::factory()->create();
    $acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $acme->users()->attach($alice);

    loadRoutesThroughTheCache("{$this->scratch}/routes.php");

    $routes = Route::getRoutes();

    expect($routes->getByName('agentic-actions.mcp.tenant')->middleware())->toContain(Discovery::class)
        ->and($routes->getByName('agentic-actions.mcp.tenant')->excludedMiddleware())->toBe([AddWwwAuthenticateHeader::class])
        ->and($routes->getByName(Discovery::METADATA)->middleware())->toContain(Discovery::class)
        ->and($routes->getByName('passport.authorizations.authorize')->middleware())->toContain(BindConsent::class)
        ->and($routes->getByName('passport.authorizations.approve')->middleware())->toContain(BindConsent::class);

    $flow = new Flow($this);

    $flow->mcp(null, '/mcp/t/acme')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/t/acme", scope="actions:read actions:write"');

    $this->getJson('/.well-known/oauth-protected-resource/mcp/t/acme')->assertJsonPath('scopes_supported', ['actions:read', 'actions:write']);

    $flow->register();
    $flow->authorize($alice, 'http://localhost/mcp/t/acme')->assertOk()->assertSee('Only in Acme.');
    $flow->approve()->assertRedirect();
    $flow->token()->assertOk();

    expect(McpConnection::for($alice)->sole()->tenant_id)->toBe($acme->id)
        ->and($flow->tools($flow->accessToken(), '/mcp/t/acme'))->toBe(['import-posts', 'list-team-posts']);
});
