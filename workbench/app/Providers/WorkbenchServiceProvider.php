<?php

namespace Workbench\App\Providers;

use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;
use Workbench\App\Tenancy\TeamMembership;
use Workbench\App\Tenancy\TeamScope;

use function Orchestra\Testbench\workbench_path;

final class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Point the package at the workbench: its actions, its snapshot and TypeScript file, the team tenant, its users,
     * the team's MCP path, the database queue its imports wait on, and Inertia's page files for #[WithPageContext].
     * This repository requires laravel/ai and inertia-laravel for development only, so the workbench declares them
     * installed, as an app would.
     */
    public static function configure(Application $app): void
    {
        $app->make('config')->set([
            'auth.providers.users.model' => User::class,
            'agentic-actions.discovery.paths' => [workbench_path('app')],
            'agentic-actions.snapshot' => workbench_path('actions.exposure.json'),
            'agentic-actions.typescript.path' => workbench_path(['resources', 'js', 'agentic', 'actions.ts']),
            'agentic-actions.tenant.model' => Team::class,
            'agentic-actions.tenant.parameter' => 'team',
            'agentic-actions.tenant.membership' => TeamMembership::class,
            'agentic-actions.tenant.scope' => TeamScope::class,
            'agentic-actions.tenancy' => null,
            'agentic-actions.mcp.tenant_path' => 'mcp/t/{team}',
            'queue.default' => 'database',
            'inertia.pages.paths' => [workbench_path(['resources', 'js', 'pages'])],
        ]);

        $app->instance(Packages::class, new Packages([
            'laravel/ai' => PackageStatus::Installed,
            'inertiajs/inertia-laravel' => PackageStatus::Installed,
        ]));
    }

    /**
     * Register the workbench's services. The Workbench test suite calls configure() itself (WorkbenchTestCase).
     */
    public function register(): void
    {
        if (class_exists(AiServiceProvider::class)) {
            $this->app->register(AiServiceProvider::class);
        }

        if (class_exists(InertiaServiceProvider::class)) {
            $this->app->register(InertiaServiceProvider::class);
        }

        if (! $this->app->runningUnitTests()) {
            self::configure($this->app);
        }
    }

    /**
     * Keep the card number out of the old input a failed form flashes.
     */
    public function boot(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if ($handler instanceof Handler) {
            $handler->dontFlash('card_number');
        }
    }
}
