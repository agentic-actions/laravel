<?php

namespace AgenticActions\Discovery;

use AgenticActions\Discovery\Console\CacheCommand;
use AgenticActions\Discovery\Console\ClearCommand;
use AgenticActions\Support\Packages;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The discovery module: the registry, the manifest's lifecycle hooks and its two commands.
 *
 * @internal
 */
final class DiscoveryServiceProvider extends ServiceProvider
{
    /**
     * Register the discovery services. The registry loads when something first asks for it, never here.
     */
    public function register(): void
    {
        $this->app->singleton(ActionRegistry::class, fn (Application $app): ActionRegistry => ActionRegistry::load($app));
    }

    /**
     * Bootstrap the discovery services: optimize and optimize:clear, a standalone route:cache and route:clear,
     * about, and the commands.
     */
    public function boot(): void
    {
        $this->optimizes(optimize: 'actions:cache', clear: 'actions:clear', key: 'agentic-actions');

        // Only a top-level `php artisan route:cache` fires CommandFinished; inside `optimize` it runs through
        // callSilently(), and optimizes() above covers that case, after route:cache.
        $this->app['events']->listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->command === 'route:cache' && $event->exitCode === 0) {
                $this->app->make(ManifestWriter::class)->write();
            }
        });

        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'route:clear') {
                $this->app->make(ManifestWriter::class)->clear();
            }
        });

        AboutCommand::add('Agentic Actions', fn (): array => [
            'Version' => $this->app->make(Packages::class)->version('agentic-actions/laravel') ?? 'unknown',
            'Actions' => count($this->app->make(ActionRegistry::class)->all()),
            'Manifest' => is_file(Manifest::path($this->app)) ? '<fg=green;options=bold>CACHED</>' : '<fg=yellow;options=bold>NOT CACHED</>',
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([CacheCommand::class, ClearCommand::class]);
        }
    }
}
