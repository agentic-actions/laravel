<?php

namespace AgenticActions\Mcp;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The MCP module: the named limiter mcp.middleware refers to, and the package's MCP server mount.
 *
 * @internal
 */
final class McpServiceProvider extends ServiceProvider
{
    /**
     * The named limiter mcp.middleware refers to.
     */
    public const LIMITER = 'agentic-actions-mcp';

    /**
     * Register the limiter, and mount the server once every other route exists.
     */
    public function boot(): void
    {
        RateLimiter::for(self::LIMITER, fn (Request $request): Limit => McpMount::limit($request));

        // An app-level booted callback runs after every provider booted, so after the app's route files and
        // routes/ai.php are registered. A cached route file already holds the mount.
        $this->app->booted(function (Application $app): void {
            if (! $app->routesAreCached()) {
                $app->make(McpMount::class)->mount();
            }
        });
    }
}
