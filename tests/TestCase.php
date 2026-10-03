<?php

namespace Tests;

use AgenticActions\Action;
use AgenticActions\AgenticActionsServiceProvider;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\Permission\PermissionServiceProvider;
use Tests\Fixtures\OAuth\OAuthRoutes;
use Tests\Fixtures\Tenancy\TeamMembership;
use Tests\Fixtures\Tenancy\TeamScope;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /**
     * The config and package statuses useOAuth() boots with, or null while OAuth is off.
     *
     * @var array{0: array<string, mixed>, 1: array<string, PackageStatus|string>, 2: string|null}|null
     */
    private ?array $oauth = null;

    /**
     * Passport's key pair for the process: [private, public].
     *
     * @var array{0: string, 1: string}|null
     */
    private static ?array $passportKeys = null;

    /**
     * Never read a .env file from the Testbench skeleton. A `vendor/bin/testbench` child process copies one there
     * while it runs (ManifestTest's route:cache case), and a test that booted beside it would inherit APP_DEBUG=true
     * and the skeleton's drivers.
     *
     * @var bool
     */
    protected $loadEnvironmentVariables = false;

    /**
     * Get the package providers.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            AgenticActionsServiceProvider::class,
            McpServiceProvider::class,
            SanctumServiceProvider::class,
            InertiaServiceProvider::class,
            PermissionServiceProvider::class,
            class_exists(AiServiceProvider::class) ? AiServiceProvider::class : null,
            class_exists(Passport::class) ? PassportServiceProvider::class : null,
        ]));
    }

    /**
     * Define the environment every test starts from. Testbench calls it after the providers register and before they boot.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('agentic-actions.discovery.paths', [__DIR__.'/Fixtures/Actions']);
        $app['config']->set('agentic-actions.snapshot', sys_get_temp_dir().'/agentic-actions-'.getmypid().'.json');

        $app->instance(Packages::class, new Packages($this->packageDefaults()));

        if ($this->oauth !== null) {
            $this->defineOAuth($app, ...$this->oauth);
        }
    }

    /**
     * useOAuth()'s environment.
     *
     * @param  Application  $app
     * @param  array<string, mixed>  $config
     * @param  array<string, PackageStatus|string>  $packages
     */
    private function defineOAuth($app, array $config, array $packages, ?string $routes): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = [(string) $private, (string) openssl_pkey_get_details($key)['key']];
        }

        $app['config']->set([
            'passport.private_key' => self::$passportKeys[0],
            'passport.public_key' => self::$passportKeys[1],
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
            'auth.providers.users.model' => User::class,
            'agentic-actions.mcp.middleware' => ['auth:sanctum,api', 'throttle:agentic-actions-mcp'],
            ...$config,
        ]);

        $app->instance(Packages::class, new Packages($packages + $this->packageDefaults()));
        $app->register(new OAuthRoutes($app, $routes));
    }

    /**
     * The package statuses every test starts from. In this repository laravel/ai and inertia-laravel are dev
     * requirements of the package itself, so Composer reports them as dev-only; tests treat them as application
     * dependencies.
     *
     * @return array<string, PackageStatus|string>
     */
    protected function packageDefaults(): array
    {
        return [
            'laravel/ai' => interface_exists(Tool::class) ? PackageStatus::Installed : PackageStatus::Missing,
            'inertiajs/inertia-laravel' => PackageStatus::Installed,
            'laravel/passport' => class_exists(Passport::class) ? PackageStatus::Installed : PackageStatus::Missing,
            'agentic-actions/laravel' => '0.1.0',
        ];
    }

    /**
     * Load Laravel's default migrations, Sanctum's, laravel/ai's and the package's conversations and views tables (when
     * laravel/ai is installed), Passport's and the package's connections table (when Passport is installed) and the workbench's
     * into the one migrate:fresh RefreshDatabase runs, until the database is migrated; later tests reuse the tables
     * inside their own transaction. Testbench's WithLaravelMigrations is not used: once the database is migrated it migrates Laravel's
     * defaults on their own and rolls them back after every test, which MySQL and Postgres refuse while posts and
     * team_user reference users.
     */
    protected function defineDatabaseMigrations(): void
    {
        if (RefreshDatabaseState::$migrated) {
            return;
        }

        $paths = [
            default_migration_path(),
            dirname(__DIR__).'/vendor/laravel/sanctum/database/migrations',
            dirname(__DIR__).'/workbench/database/migrations',
        ];

        // The package's conversation store references laravel/ai's conversations table; the tables shown sit beside it.
        if (is_dir($ai = dirname(__DIR__).'/vendor/laravel/ai/database/migrations')) {
            array_push($paths, $ai, dirname(__DIR__).'/database/migrations/2026_09_26_000001_create_agentic_conversations_table.php', dirname(__DIR__).'/database/migrations/2026_09_30_000001_create_agentic_views_table.php');
        }

        if (class_exists(Passport::class)) {
            array_push($paths, dirname(__DIR__).'/vendor/laravel/passport/database/migrations', dirname(__DIR__).'/database/migrations/2026_09_27_000001_create_agentic_mcp_connections_table.php');
        }

        $this->loadMigrationsFrom($paths);
    }

    /**
     * Skip a test that needs laravel/ai when it is not installed (the "no laravel/ai" CI cell).
     */
    protected function skipUnlessAi(): void
    {
        if (! interface_exists(Tool::class)) {
            $this->markTestSkipped('laravel/ai is not installed.');
        }
    }

    /**
     * Skip a test that needs laravel/passport when it is not installed (the CI cell without the optional packages).
     */
    protected function skipUnlessPassport(): void
    {
        if (! class_exists(Passport::class)) {
            $this->markTestSkipped('laravel/passport is not installed.');
        }
    }

    /**
     * Boot the application again with OAuth on, as an app turns it on: Passport's keys (PEM strings, made once per
     * process, so no key files), an api guard on Passport's driver named after Sanctum's in mcp.middleware,
     * Mcp::oauthRoutes() and a login route, registered as routes/ai.php and routes/web.php are, before the mount. The
     * config and package statuses given apply over those, before the test creates any data. $routes says when
     * Mcp::oauthRoutes() registers: "boot" in a provider booted after the package's, as routes/ai.php does; "register"
     * before any provider boots; null never.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, PackageStatus|string>  $packages
     */
    protected function useOAuth(array $config = [], array $packages = [], ?string $routes = 'boot'): void
    {
        $this->skipUnlessPassport();

        $this->oauth = [$config, $packages, $routes];

        ClassExposure::flush();

        $this->reloadApplication();
    }

    /**
     * Make the workbench's Team the tenant model, with the fixture membership and scope. Call it before anything
     * resolves the registry or mounts routes.
     */
    protected function useTeamTenancy(): void
    {
        config([
            'agentic-actions.tenant.model' => Team::class,
            'agentic-actions.tenant.parameter' => 'team',
            'agentic-actions.tenant.membership' => TeamMembership::class,
            'agentic-actions.tenant.scope' => TeamScope::class,
        ]);

        $this->refreshActions();
    }

    /**
     * Rebind the package statuses and versions over the defaults, for example laravel/ai as Missing.
     *
     * @param  array<string, PackageStatus|string>  $overrides
     */
    protected function usePackages(array $overrides): void
    {
        $this->app->instance(Packages::class, new Packages($overrides + $this->packageDefaults()));

        $this->refreshActions();
    }

    /**
     * Forget memoized class facts and the registry after a config or package change inside a test.
     */
    protected function refreshActions(): void
    {
        ClassExposure::flush();

        $this->app->forgetInstance(ActionRegistry::class);
    }

    /**
     * Register routes inside a test, then refresh the router's name lookups, which route files get at boot.
     */
    protected function mountRoutes(Closure $routes): void
    {
        $routes();

        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * Register one route exactly as Actions::routes() would, for tests written before the HTTP module exists.
     * Call it inside mountRoutes() and the group the test needs.
     *
     * @param  class-string<Action>  $class
     */
    protected function generatedRoute(string $class): RoutingRoute
    {
        $segment = ClassExposure::of($class)->segment();

        $route = Route::post($segment, $class)
            ->middleware(HandlePrecognitiveRequests::class)
            ->name($segment);

        $route->setAction([...$route->getAction(), ActionRequest::GENERATED => true]);

        return $route;
    }

    /**
     * Forget class facts memoized by a previous test, before this test's application boots.
     */
    protected function setUp(): void
    {
        ClassExposure::flush();

        if (class_exists(Passport::class)) {
            Passport::$scopes = [];
            Passport::$tokensExpireIn = null;
            Passport::$refreshTokensExpireIn = null;
            Passport::$keyPath = null;
        }

        parent::setUp();
    }
}
