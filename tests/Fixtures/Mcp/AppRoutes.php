<?php

namespace Tests\Fixtures\Mcp;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * An app provider booted after the package's, which registers routes in its own boot, as an app's route provider does.
 */
final class AppRoutes extends ServiceProvider
{
    /**
     * Create the provider.
     */
    public function __construct(Application $app, private readonly Closure $routes)
    {
        parent::__construct($app);
    }

    /**
     * Register the routes.
     */
    public function boot(): void
    {
        ($this->routes)();
    }
}
