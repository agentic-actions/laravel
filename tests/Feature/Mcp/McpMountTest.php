<?php

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Mcp\McpMount;
use AgenticActions\Mcp\McpServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Mcp\Server\Middleware\ValidateMcpHeaders;
use Tests\Fixtures\Mcp\Actions\McpReadPosts;
use Tests\Fixtures\Mcp\Actions\McpTeamRead;
use Tests\Fixtures\Mcp\IdTeam;
use Tests\Fixtures\Mcp\JsonRpc;
use Tests\Fixtures\Mcp\KeyRefusingTeam;
use Tests\Fixtures\Mcp\McpEnvironment;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/*
 * The mount: one laravel/mcp server per path, after every other route exists, never over a route the app holds, and
 * constrained so a non-canonical tenant segment is a 404 on every method.
 */

uses(McpEnvironment::class);

/**
 * Every route whose URI is this one, as "METHODS uri".
 *
 * @return list<string>
 */
function mcpRoutesAt(string $uri): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => $route->uri() === $uri)
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();
}

describe('the routes', function () {
    it('mounts each path with its name, the mark and mcp.middleware, after laravel/mcp\'s own', function () {
        $route = Route::getRoutes()->getByName(McpMount::ROUTE);
        $tenant = Route::getRoutes()->getByName(McpMount::TENANT_ROUTE);

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe('mcp/actions')
            ->and($route->methods())->toBe(['POST'])
            ->and($route->getAction(McpMount::MARK))->toBeTrue()
            ->and($route->middleware())->toBe([
                ReorderJsonAccept::class,
                ValidateMcpHeaders::class,
                AddWwwAuthenticateHeader::class,
                'auth:sanctum',
                'throttle:agentic-actions-mcp',
            ])
            ->and(mcpRoutesAt('mcp/actions'))->toBe(['GET|HEAD mcp/actions', 'DELETE mcp/actions', 'POST mcp/actions'])
            ->and($tenant)->not->toBeNull()
            ->and($tenant->uri())->toBe('mcp/t/{team}')
            ->and($tenant->getAction(McpMount::MARK))->toBeTrue()
            ->and($tenant->wheres)->toBe([]);
    });

    it('resolves both names under Testbench, whose own booted callback refreshed the lookups first', function () {
        expect(route(McpMount::ROUTE))->toBe(url('mcp/actions'))
            ->and(route(McpMount::TENANT_ROUTE, ['team' => 'acme']))->toBe(url('mcp/t/acme'));
    });
});

describe('the tenant segment', function () {
    it('answers 404 on every method for a segment the derived digit pattern refuses', function (string $segment) {
        $this->bootMcp(['agentic-actions.tenant.model' => IdTeam::class]);

        expect(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE)?->wheres)->toBe(['team' => '[1-9][0-9]*']);

        $this->postJson("/mcp/t/{$segment}", JsonRpc::legacy('tools/list'))->assertNotFound();
        $this->get("/mcp/t/{$segment}")->assertNotFound();
        $this->delete("/mcp/t/{$segment}")->assertNotFound();

        $this->get('/mcp/t/7')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->postJson('/mcp/t/7', JsonRpc::legacy('tools/list'))->assertUnauthorized();
    })->with(['abc', '001', '0']);

    it('keeps a configured pattern, and derives none for a slug route key', function () {
        expect(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE)?->wheres)->toBe([]);

        $this->bootMcp(['agentic-actions.mcp.tenant_pattern' => '[a-z]+']);

        expect(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE)?->wheres)->toBe(['team' => '[a-z]+']);

        $this->get('/mcp/t/acme7')->assertNotFound();
        $this->get('/mcp/t/acme')->assertStatus(405);
    });
});

describe('what does not mount', function () {
    it('mounts each path unless unmounted() gives a reason, and gives the first reason that holds', function (array $config, ?string $base, ?string $tenant) {
        $this->bootMcp($config);

        expect(McpMount::unmounted(false))->toBe($base)
            ->and(McpMount::unmounted(true))->toBe($tenant)
            ->and(Route::getRoutes()->getByName(McpMount::ROUTE) !== null)->toBe($base === null)
            ->and(mcpRoutesAt('mcp/actions') !== [])->toBe($base === null)
            // Without a tenant model every action is served on the base path, so no tenant path mounts.
            ->and(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE) !== null)->toBe($tenant === null && config('agentic-actions.tenant.model') !== null);
    })->with([
        'no MCP action' => [['agentic-actions.discovery.paths' => []], 'no action of this scope allows MCP', 'no action of this scope allows MCP'],
        'no account-level MCP action' => [['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [McpTeamRead::class]], 'no action of this scope allows MCP', null],
        'no tenant-scoped MCP action' => [['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => [McpReadPosts::class]], null, 'no action of this scope allows MCP'],
        'surfaces.mcp off' => [['agentic-actions.surfaces.mcp' => false], 'surfaces.mcp is off', 'surfaces.mcp is off'],
        'surfaces.mcp off before every other reason' => [[
            'agentic-actions.surfaces.mcp' => false,
            'agentic-actions.mcp.middleware' => ['throttle:agentic-actions-mcp'],
            'agentic-actions.mcp.path' => null,
            'agentic-actions.discovery.paths' => [],
        ], 'surfaces.mcp is off', 'surfaces.mcp is off'],
        'no configured guard' => [['agentic-actions.mcp.middleware' => ['auth:missing', 'throttle:agentic-actions-mcp']], 'mcp.middleware names no configured guard', 'mcp.middleware names no configured guard'],
        'no auth middleware' => [['agentic-actions.mcp.middleware' => ['throttle:agentic-actions-mcp', 'authorize', 'auth:']], 'mcp.middleware names no configured guard', 'mcp.middleware names no configured guard'],
        'no guard before a null path' => [[
            'agentic-actions.mcp.middleware' => ['throttle:agentic-actions-mcp'],
            'agentic-actions.mcp.path' => null,
            'agentic-actions.mcp.tenant_path' => null,
        ], 'mcp.middleware names no configured guard', 'mcp.middleware names no configured guard'],
        'a missing guard before a configured one' => [['agentic-actions.mcp.middleware' => ['auth:missing,sanctum', 'throttle:agentic-actions-mcp']], null, null],
        'a null base path' => [['agentic-actions.mcp.path' => null], 'agentic-actions.mcp.path is null', null],
        'a base path of a slash' => [['agentic-actions.mcp.path' => '/'], 'agentic-actions.mcp.path is null', null],
        'an empty base path' => [['agentic-actions.mcp.path' => ''], 'agentic-actions.mcp.path is null', null],
        'a null path before no action' => [['agentic-actions.mcp.path' => null, 'agentic-actions.discovery.paths' => []], 'agentic-actions.mcp.path is null', 'no action of this scope allows MCP'],
        'a null tenant path' => [['agentic-actions.mcp.tenant_path' => null], null, 'agentic-actions.mcp.tenant_path is null'],
        'a tenant path without {team}' => [['agentic-actions.mcp.tenant_path' => 'mcp/t/{tenant}'], null, 'agentic-actions.mcp.tenant_path has no {team}'],
        'no tenant model' => [['agentic-actions.tenant.model' => null], null, null],
    ]);

    it('loads no registry at boot when no guard is configured, as in a fresh app without a token guard', function () {
        expect($this->app->resolved(ActionRegistry::class))->toBeTrue();

        $this->bootMcp(['agentic-actions.mcp.middleware' => ['throttle:agentic-actions-mcp']]);

        expect($this->app->resolved(ActionRegistry::class))->toBeFalse()
            ->and(McpMount::unmounted(false))->toBe('mcp.middleware names no configured guard');
    });

    it('serves every MCP action on the base path when no tenant model is set', function () {
        $this->bootMcp(['agentic-actions.tenant.model' => null]);

        $user = User::factory()->create();
        $token = $user->createToken('t', ['actions:read'])->plainTextToken;

        $tools = $this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $token)->json('result.tools.*.name');

        expect($tools)->toBe(['mcp-read-posts', 'mcp-team-read']);
    });
});

describe('the tenant a segment names, and the budget key', function () {
    it('returns a tenant model as is, and null for another model, an unknown segment or no tenant model', function () {
        $team = Team::factory()->create(['slug' => 'acme']);
        $user = User::factory()->create();

        expect(McpMount::tenant($team))->toBe($team)
            ->and(McpMount::tenant($user))->toBeNull()
            ->and(McpMount::tenant('acme')?->is($team))->toBeTrue()
            ->and(McpMount::tenant('nobody'))->toBeNull()
            ->and(McpMount::tenant(''))->toBeNull()
            ->and(McpMount::tenant(null))->toBeNull();

        config(['agentic-actions.tenant.model' => null]);

        expect(McpMount::tenant('acme'))->toBeNull();
    });

    it('answers null when the key column refuses the value', function () {
        config(['agentic-actions.tenant.model' => KeyRefusingTeam::class]);

        expect(McpMount::tenant('abc'))->toBeNull();
    });

    it('keys an actor that is not a model by its class and identifier, and a missing actor by ip', function () {
        $request = function (mixed $actor): Request {
            $request = Request::create('/mcp/actions', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
            $request->setUserResolver(fn () => $actor);

            return $request;
        };

        expect(McpMount::limit($request(new GenericUser(['id' => 5])))->key)->toBe(hash('sha256', GenericUser::class.':5'))
            ->and(McpMount::limit($request(null))->key)->toBe(hash('sha256', 'ip:203.0.113.9'));
    });
});

describe('collisions', function () {
    it('keeps a POST route an app provider booted after the package\'s registered, and does not mount there', function () {
        $this->bootMcp(appRoutes: fn () => Route::post('mcp/actions', fn (): string => 'the app server'));

        expect(Route::getRoutes()->getByName(McpMount::ROUTE))->toBeNull()
            ->and(mcpRoutesAt('mcp/actions'))->toBe(['POST mcp/actions'])
            ->and(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE))->not->toBeNull();

        $this->post('/mcp/actions')->assertOk()->assertSee('the app server');
    });

    it('keeps an app\'s GET page at the path, which still answers', function () {
        $this->bootMcp(appRoutes: fn () => Route::get('mcp/actions', fn (): string => 'a page'));

        expect(Route::getRoutes()->getByName(McpMount::ROUTE))->toBeNull()
            ->and(mcpRoutesAt('mcp/actions'))->toBe(['GET|HEAD mcp/actions']);

        $this->get('/mcp/actions')->assertOk()->assertSee('a page');
    });

    it('keeps an app\'s DELETE route at the tenant path', function () {
        $this->bootMcp(appRoutes: fn () => Route::delete('mcp/t/{team}', fn (): string => 'gone'));

        expect(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE))->toBeNull()
            ->and(mcpRoutesAt('mcp/t/{team}'))->toBe(['DELETE mcp/t/{team}'])
            ->and(Route::getRoutes()->getByName(McpMount::ROUTE))->not->toBeNull();
    });

    it('treats a path with slashes around it as the same URI', function () {
        $this->bootMcp(['agentic-actions.mcp.path' => '/mcp/actions/'], fn () => Route::post('mcp/actions', fn (): string => 'app'));

        expect(Route::getRoutes()->getByName(McpMount::ROUTE))->toBeNull();
    });
});

describe('the route cache', function () {
    it('keeps the mount, its names and its mark through the cached route format', function () {
        $path = sys_get_temp_dir().'/agentic-actions-mcp-routes-'.getmypid().'.php';

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        File::put($path, '<?php app(\'router\')->setCompiledRoutes('.var_export(Route::getRoutes()->compile(), true).');');

        try {
            Route::setRoutes(new RouteCollection);

            require $path;
        } finally {
            File::delete($path);
        }

        $route = Route::getRoutes()->getByName(McpMount::ROUTE);

        expect($route?->getAction(McpMount::MARK))->toBeTrue()
            ->and(Route::getRoutes()->getByName(McpMount::TENANT_ROUTE)?->getAction(McpMount::MARK))->toBeTrue();

        $user = User::factory()->create();
        $token = $user->createToken('t', ['actions:read'])->plainTextToken;

        expect($this->mcp('/mcp/actions', JsonRpc::legacy('tools/list'), $token)->json('result.tools.*.name'))->toBe(['mcp-read-posts']);
    });

    it('mounts nothing from the booted callback once the routes are cached', function () {
        Route::setRoutes(new RouteCollection);
        $this->app->instance('routes.cached', true);

        (new McpServiceProvider($this->app))->boot();

        expect(Route::getRoutes()->getRoutes())->toBe([]);

        $this->app->instance('routes.cached', false);

        (new McpServiceProvider($this->app))->boot();

        expect(Route::getRoutes()->getByName(McpMount::ROUTE))->not->toBeNull();
    });
});
