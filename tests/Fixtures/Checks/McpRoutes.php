<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Mcp\McpMount;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;

/**
 * The package's MCP routes, registered by hand the way McpMount::mount() registers them, so the console tests read a
 * mount without depending on it. The server class is never started here.
 */
final class McpRoutes
{
    /**
     * Mcp::web()'s GET, DELETE and POST routes at the URI, the POST one named and marked as the package's; then
     * refresh the name lookups, as the mount does.
     */
    public static function mount(string $uri, string $name = McpMount::ROUTE): Route
    {
        $route = Mcp::web($uri, Server::class)
            ->middleware((array) config('agentic-actions.mcp.middleware', []))
            ->name($name);

        $route->setAction([...$route->getAction(), McpMount::MARK => true]);

        Router::getRoutes()->refreshNameLookups();

        return $route;
    }
}
