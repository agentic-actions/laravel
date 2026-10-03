<?php

namespace AgenticActions\OAuth;

use AgenticActions\Mcp\McpMount;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * What tells a client how to sign in to a package MCP path: on the path, the 401 challenge naming its metadata and its
 * scopes; on laravel/mcp's protected-resource metadata route, the path's scopes. It never resolves a tenant, so an
 * unknown tenant's answers match a known one's.
 *
 * @upstream The package writes its MCP routes' challenge and lists their scopes in their resource metadata.
 *
 * @internal
 */
final class Discovery
{
    /**
     * The name of laravel/mcp's protected-resource metadata route for a path.
     */
    public const METADATA = 'mcp.oauth.protected-resource.nested';

    /**
     * Answer a package path's metadata with its scopes, or set the challenge on a package MCP route's 401.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();

        if ($route instanceof RoutingRoute && $route->getName() === self::METADATA) {
            $path = McpMount::path(url('/'.(string) $route->parameter('path')));

            if ($path !== null && $response instanceof JsonResponse) {
                $response->setData([...(array) $response->getData(true), 'scopes_supported' => McpMount::scopes($path[0] === McpMount::TENANT_ROUTE)]);
            }
        } elseif ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', Route::has(self::METADATA)
                ? 'Bearer realm="mcp", resource_metadata="'.route(self::METADATA, ['path' => $request->path()]).'", scope="'.implode(' ', McpMount::scopes($route instanceof RoutingRoute && $route->getName() === McpMount::TENANT_ROUTE)).'"'
                : 'Bearer realm="mcp", error="invalid_token"');
        }

        return $response;
    }
}
