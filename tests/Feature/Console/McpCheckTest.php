<?php

use AgenticActions\Console\Checks;
use AgenticActions\Console\Finding;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Facades\Actions;
use AgenticActions\Mcp\McpMount;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Tests\Fixtures\Actions\CreateNote;
use Tests\Fixtures\Actions\PlainNote;
use Tests\Fixtures\Actions\TeamNote;
use Tests\Fixtures\Checks\McpAccount;
use Tests\Fixtures\Checks\McpNamed;
use Tests\Fixtures\Checks\McpRoutes;
use Tests\Fixtures\Checks\TenantKeyInSchema;
use Tests\Fixtures\Install\PassportUser;
use Workbench\App\Models\User;

/*
 * The rows read the final route collection, so each test starts from an empty one and registers the package's MCP
 * routes by hand (McpRoutes), whether or not the mount ran at boot. The user model is on Sanctum's trait, as after
 * php artisan install:api.
 */
beforeEach(function () {
    app('router')->setRoutes(new RouteCollection);

    config(['auth.providers.users.model' => User::class]);
});

afterEach(function () {
    File::delete(Snapshot::path());
});

/**
 * The findings of these rows for exactly these classes, as [level, row, message] triples. The registry is rebuilt from
 * the same classes, since McpMount::unmounted() reads it.
 *
 * @param  list<class-string>  $classes
 * @return list<array{0: string, 1: string, 2: string}>
 */
function mcpFindings(array $classes, string ...$rows): array
{
    config(['agentic-actions.discovery.paths' => [], 'agentic-actions.discovery.classes' => $classes]);

    ClassExposure::flush();
    app()->forgetInstance(ActionRegistry::class);

    return array_values(array_map(
        fn (Finding $finding): array => [$finding->level, $finding->row, $finding->message],
        array_filter(app(Checks::class)->run(), fn (Finding $finding): bool => in_array($finding->row, $rows, true)),
    ));
}

describe('abilities', function () {
    it('fails two effects that share an ability', function () {
        config(['agentic-actions.abilities.destructive' => 'actions:write']);

        expect(mcpFindings([CreateNote::class], 'Abilities'))->toBe([
            ['fail', 'Abilities', 'Two effects share the token ability [actions:write] in agentic-actions.abilities, so a token for one reaches the other. Give each effect its own ability.'],
        ]);
    });

    it('fails an ability of * or mcp:use', function () {
        config(['agentic-actions.abilities.read' => '*', 'agentic-actions.abilities.external' => 'mcp:use']);

        expect(mcpFindings([CreateNote::class], 'Abilities'))->toBe([
            ['fail', 'Abilities', 'The ability for read is [*], which the package does not accept: * never counts inside MCP, and mcp:use is the MCP OAuth scope. Use a name of your own, such as actions:read.'],
            ['fail', 'Abilities', 'The ability for external is [mcp:use], which the package does not accept: * never counts inside MCP, and mcp:use is the MCP OAuth scope. Use a name of your own, such as actions:external.'],
        ]);
    });

    // PlainNote allows no MCP: the abilities are read whatever the actions allow.
    it('fails an empty or null tenant prefix', function (?string $prefix) {
        config(['agentic-actions.abilities.tenant' => $prefix]);

        expect(mcpFindings([PlainNote::class], 'Abilities'))->toBe([
            ['fail', 'Abilities', 'agentic-actions.abilities.tenant is empty, so no token can be bound to one tenant and every tenant:… ability is ignored. Set it back to tenant: or another prefix of your own.'],
        ]);
    })->with(['empty' => '', 'null' => null]);

    it('fails an effect ability that starts with the tenant prefix', function () {
        config(['agentic-actions.abilities.write' => 'tenant:write']);

        expect(mcpFindings([CreateNote::class], 'Abilities'))->toBe([
            ['fail', 'Abilities', 'The ability for write is [tenant:write], which starts with the tenant prefix [tenant:] and would read as a tenant binding. Use a name of your own, such as actions:write.'],
        ]);
    });
});

describe('the MCP guard', function () {
    it('warns for an action naming mcp: true while no guard is configured, and stays silent for a bare #[Expose]', function () {
        config(['agentic-actions.mcp.middleware' => ['auth:api', 'throttle:agentic-actions-mcp']]);

        expect(mcpFindings([McpNamed::class, CreateNote::class], 'MCP guard'))->toBe([
            ['warn', 'MCP guard', McpNamed::class.' names mcp: true, but agentic-actions.mcp.middleware names no guard that auth.guards defines, so MCP is not mounted. Add auth:{guard} for a token guard (auth:sanctum after php artisan install:api).'],
        ]);
    });

    it('stays silent for mcp: true with a configured guard, or with MCP off', function () {
        $configured = mcpFindings([McpNamed::class], 'MCP guard');

        config(['agentic-actions.mcp.middleware' => [], 'agentic-actions.surfaces.mcp' => false]);

        expect($configured)->toBe([])
            ->and(mcpFindings([McpNamed::class], 'MCP guard'))->toBe([]);
    });

    it('fails a guard with the session driver, named or the default', function (string $middleware) {
        config(['agentic-actions.mcp.middleware' => [$middleware]]);

        expect(mcpFindings([CreateNote::class], 'MCP guard'))->toBe([
            ['fail', 'MCP guard', 'The MCP guard [web] uses the session driver. MCP clients send tokens, and inside MCP a session grants nothing: name a token guard in agentic-actions.mcp.middleware.'],
        ]);
    })->with(['auth:web', 'auth']);

    it('warns for a Sanctum guard whose user model uses no HasApiTokens trait, and stays silent with MCP off', function () {
        config(['auth.providers.users.model' => AuthUser::class]);

        $warned = mcpFindings([CreateNote::class], 'MCP guard');

        config(['agentic-actions.surfaces.mcp' => false]);

        expect($warned)->toBe([
            ['warn', 'MCP guard', 'The MCP guard [sanctum] reads Sanctum tokens, but its user model does not use Sanctum\'s HasApiTokens, so no token signs anyone in. Add this line inside '.AuthUser::class.': use \Laravel\Sanctum\HasApiTokens;'],
        ])->and(mcpFindings([CreateNote::class], 'MCP guard'))->toBe([]);
    });

    it('warns once for a Passport guard, alone or beside Sanctum, whose user model uses no HasApiTokens trait', function (string $middleware, string $guard, string $package) {
        config([
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
            'auth.providers.users.model' => AuthUser::class,
            'agentic-actions.mcp.middleware' => [$middleware, 'throttle:agentic-actions-mcp'],
        ]);

        expect(mcpFindings([CreateNote::class], 'MCP guard'))->toBe([
            ['warn', 'MCP guard', "The MCP guard [{$guard}] reads {$package} tokens, but its user model does not use {$package}'s HasApiTokens, so no token signs anyone in. Add this line inside ".AuthUser::class.": use \\Laravel\\{$package}\\HasApiTokens;"],
        ]);
    })->with([
        'Passport alone' => ['auth:api', 'api', 'Passport'],
        'Passport beside Sanctum' => ['auth:sanctum,api', 'sanctum', 'Sanctum'],
    ]);

    it('passes the documented token setups: Sanctum\'s trait for Sanctum alone or beside Passport, Passport\'s for Passport alone', function (string $model, string $middleware) {
        if ($model === PassportUser::class) {
            $this->skipUnlessPassport();
        }

        config([
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
            'auth.providers.users.model' => $model,
            'agentic-actions.mcp.middleware' => [$middleware, 'throttle:agentic-actions-mcp'],
        ]);

        expect(mcpFindings([CreateNote::class], 'MCP guard'))->toBe([]);
    })->with([
        'Sanctum alone' => [User::class, 'auth:sanctum'],
        'Sanctum and Passport' => [User::class, 'auth:sanctum,api'],
        'Passport alone' => [PassportUser::class, 'auth:api'],
    ]);
});

describe('the MCP route', function () {
    it('passes the package\'s own routes, the GET and DELETE routes beside the marked POST route included', function () {
        McpRoutes::mount('mcp/actions');

        expect(Route::getRoutes()->getRoutes())->toHaveCount(3)
            ->and(mcpFindings([CreateNote::class], 'MCP route'))->toBe([]);
    });

    it('fails a route an app registers at mcp.path itself', function (string $method, string $methods) {
        Route::{$method}('mcp/actions', fn () => 'mine');

        expect(mcpFindings([CreateNote::class], 'MCP route'))->toBe([
            ['fail', 'MCP route', "The route [{$methods} mcp/actions] is not the package's MCP server, so the package did not mount there. Move that route, or set agentic-actions.mcp.path (or tenant_path)."],
        ]);
    })->with(['a POST route' => ['post', 'POST'], 'a GET page' => ['get', 'GET|HEAD']]);

    it('warns when nothing answers POST at a path that should mount', function () {
        expect(mcpFindings([CreateNote::class], 'MCP route'))->toBe([
            ['warn', 'MCP route', 'Nothing answers POST [mcp/actions]. After changing MCP settings, run php artisan route:cache again and reload PHP-FPM.'],
        ]);
    });

    it('reads nothing at a path that does not mount', function (array $settings) {
        Route::post('mcp/actions', fn () => 'mine');

        config($settings);

        expect(mcpFindings([CreateNote::class], 'MCP route'))->toBe([]);
    })->with([
        'MCP off' => [['agentic-actions.surfaces.mcp' => false]],
        'a null path' => [['agentic-actions.mcp.path' => null]],
        'no guard' => [['agentic-actions.mcp.middleware' => []]],
    ]);

    it('fails a tenant path without the tenant parameter', function () {
        $this->useTeamTenancy();
        config(['agentic-actions.mcp.tenant_path' => 'mcp/t/{tenant}']);
        McpRoutes::mount('mcp/actions');

        expect(mcpFindings([TeamNote::class, McpAccount::class], 'MCP route'))->toBe([
            ['fail', 'MCP route', 'agentic-actions.mcp.tenant_path [mcp/t/{tenant}] must contain {team}, the tenant route parameter.'],
        ]);
    });

    it('warns for each tenant-scoped MCP action while the tenant path is null', function () {
        $this->useTeamTenancy();
        McpRoutes::mount('mcp/actions');

        expect(mcpFindings([TeamNote::class, McpAccount::class], 'MCP route'))->toBe([
            ['warn', 'MCP route', TeamNote::class.' is tenant-scoped and allows MCP, but agentic-actions.mcp.tenant_path is null, so no MCP client can reach it. Set a tenant path such as mcp/t/{team}.'],
        ]);
    });

    it('checks the tenant path as it checks the base path', function () {
        $this->useTeamTenancy();
        config(['agentic-actions.mcp.tenant_path' => 'mcp/t/{team}']);
        McpRoutes::mount('mcp/actions');

        $missing = mcpFindings([TeamNote::class, McpAccount::class], 'MCP route');

        McpRoutes::mount('mcp/t/{team}', McpMount::TENANT_ROUTE);

        expect($missing)->toBe([
            ['warn', 'MCP route', 'Nothing answers POST [mcp/t/{team}]. After changing MCP settings, run php artisan route:cache again and reload PHP-FPM.'],
        ])->and(mcpFindings([TeamNote::class, McpAccount::class], 'MCP route'))->toBe([]);
    });
});

describe('token routes', function () {
    it('warns about a route on the MCP guard that checks no ability', function () {
        McpRoutes::mount('mcp/actions');
        Route::middleware('auth:sanctum')->get('api/user', fn () => 'me');

        expect(mcpFindings([CreateNote::class], 'Token routes'))->toBe([
            ['warn', 'Token routes', 'The route [GET|HEAD api/user] authenticates with the MCP guard [sanctum] and checks no ability, so an MCP token reaches it too. Add abilities:… middleware, or move it off that guard.'],
        ]);
    });

    it('passes a route that checks an ability, by alias, by an unresolved name or through its group', function () {
        app('router')->aliasMiddleware('ability', CheckForAnyAbility::class);

        Route::middleware(['auth:sanctum', 'ability:profile:read'])->get('api/user', fn () => 'me');
        Route::middleware(['auth:sanctum', 'abilities:profile:read'])->get('api/me', fn () => 'me');
        Route::middleware('abilities:profile:read')->group(fn () => Route::middleware('auth:sanctum')->get('api/profile', fn () => 'me'));

        expect(mcpFindings([CreateNote::class], 'Token routes'))->toBe([]);
    });

    it('leaves out other guards, the package\'s routes and the action routes', function () {
        McpRoutes::mount('mcp/actions');
        Route::middleware('auth')->get('dashboard', fn () => 'home');
        Route::middleware('auth:sanctum')->prefix('api/actions')->group(fn () => $this->generatedRoute(CreateNote::class));
        Route::middleware('auth:sanctum')->post('api/notes', CreateNote::class);

        expect(mcpFindings([CreateNote::class], 'Token routes'))->toBe([]);
    });

    it('leaves out the change feed and the tables\' refresh, which refuse a token themselves', function () {
        McpRoutes::mount('mcp/actions');
        $this->mountRoutes(fn () => Route::middleware('auth:sanctum')->prefix('api')->name('api.')->group(fn () => Actions::routes()));

        expect(Route::getRoutes()->getByName('api.actions._views'))->not->toBeNull()
            ->and(mcpFindings([CreateNote::class], 'Token routes'))->toBe([]);
    });

    it('leaves a session MCP guard to the MCP guard row', function () {
        config(['agentic-actions.mcp.middleware' => ['auth:web']]);
        Route::middleware('auth')->get('dashboard', fn () => 'home');

        expect(mcpFindings([CreateNote::class], 'MCP guard', 'Token routes'))->toBe([
            ['fail', 'MCP guard', 'The MCP guard [web] uses the session driver. MCP clients send tokens, and inside MCP a session grants nothing: name a token guard in agentic-actions.mcp.middleware.'],
        ]);
    });
});

it('reports nothing about MCP when no action allows it', function () {
    Route::post('mcp/actions', fn () => 'mine');
    Route::middleware('auth:sanctum')->get('api/user', fn () => 'me');

    config(['agentic-actions.mcp.middleware' => ['auth:web']]);

    expect(mcpFindings([PlainNote::class, TenantKeyInSchema::class], 'MCP guard', 'MCP route', 'Token routes'))->toBe([]);
});
