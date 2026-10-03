<?php

namespace Tests\Fixtures\OAuth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

/**
 * The routes an app with OAuth has before the package mounts: Mcp::oauthRoutes() behind a throttle, as routes/ai.php
 * holds it, and a login route, as every starter kit has. They register when the provider boots, after the package's
 * provider, or when it registers, before any provider boots.
 */
final class OAuthRoutes extends ServiceProvider
{
    /**
     * Create the provider.
     */
    public function __construct(Application $app, private readonly ?string $when = 'boot')
    {
        parent::__construct($app);
    }

    /**
     * Register the routes now when asked to come before every provider boots.
     */
    public function register(): void
    {
        if ($this->when === 'register') {
            $this->routes();
        }
    }

    /**
     * Register the routes as an app's route provider does.
     */
    public function boot(): void
    {
        if ($this->when !== 'register') {
            $this->routes();
        }
    }

    /**
     * Mcp::oauthRoutes() unless left out, and the login route.
     */
    private function routes(): void
    {
        if ($this->when !== null) {
            Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());
        }

        Route::middleware('web')->get('login', fn (): string => 'Sign in')->name('login');
    }
}
