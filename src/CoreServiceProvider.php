<?php

namespace AgenticActions;

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Contracts\Tenancy;
use AgenticActions\Pipeline\RunningContexts;
use AgenticActions\Security\ForbiddenKeys;
use AgenticActions\Security\ReadGuard;
use AgenticActions\Security\TokenGrants;
use AgenticActions\Support\Packages;
use AgenticActions\Tenancy\NullTenancy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use LogicException;
use WeakMap;

/**
 * The core module: the pipeline, the security pieces and tenancy.
 *
 * @internal
 */
final class CoreServiceProvider extends ServiceProvider
{
    /**
     * The connections that already carry the Read guard's hook.
     *
     * @var WeakMap<Connection, true>|null
     */
    private ?WeakMap $guarded = null;

    /**
     * Register the core services. Nothing is resolved here, so tests and the workbench can rebind before boot.
     */
    public function register(): void
    {
        $this->app->singleton(ActionsManager::class);
        $this->app->singleton(Runner::class);
        $this->app->singleton(Packages::class);
        $this->app->singleton(ReadsTokenGrants::class, TokenGrants::class);
        $this->app->scoped(ReadGuard::class);
        $this->app->scoped(ForbiddenKeys::class);
        $this->app->scoped(RunningContexts::class);

        $this->app->singleton(Tenancy::class, function (Application $app): Tenancy {
            $tenancy = $app->make(config('agentic-actions.tenancy') ?? NullTenancy::class);

            if (! $tenancy instanceof Tenancy) {
                throw new LogicException('agentic-actions.tenancy must name a '.Tenancy::class.' class.');
            }

            return $tenancy;
        });
    }

    /**
     * Bootstrap the core services: the language lines and the Read guard's hook on every connection.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'agentic-actions');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/agentic-actions'),
            ], 'agentic-actions-lang');
        }

        $this->app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->guard($event->connection);
        });

        foreach ($this->app['db']->getConnections() as $connection) {
            $this->guard($connection);
        }
    }

    /**
     * Refuse writes during a Read on one connection, before each statement executes. Every statement Laravel runs
     * goes through Connection::run(), which calls these callbacks first.
     */
    private function guard(Connection $connection): void
    {
        $this->guarded ??= new WeakMap;

        if (isset($this->guarded[$connection])) {
            return;
        }

        $this->guarded[$connection] = true;

        // Resolved on every statement, so the current request's guard answers.
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            app(ReadGuard::class)->check($query, $connection);
        });
    }
}
