<?php

namespace AgenticActions\TypeScript;

use Illuminate\Support\ServiceProvider;

/**
 * The TypeScript module: the actions:typescript command, beside the emitter it runs.
 *
 * @internal
 */
final class TypeScriptServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the TypeScript services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TypeScriptCommand::class]);
        }
    }
}
