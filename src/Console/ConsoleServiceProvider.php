<?php

namespace AgenticActions\Console;

use Illuminate\Support\ServiceProvider;

/**
 * The console module: actions:install, actions:run, actions:list, actions:check and make:agentic-action.
 * actions:typescript lives in the TypeScript module, and actions:cache and actions:clear in discovery.
 *
 * @internal
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the console services: the commands, and the publishable action stub.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            RunCommand::class,
            ListCommand::class,
            CheckCommand::class,
            MakeActionCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../../stubs/agentic-action.stub' => $this->app->basePath('stubs/agentic-action.stub'),
        ], 'agentic-actions-stubs');
    }
}
