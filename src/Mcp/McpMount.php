<?php

namespace AgenticActions\Mcp;

use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Effect;
use AgenticActions\Exposure\Entry;
use AgenticActions\OAuth\BindConsent;
use AgenticActions\OAuth\Discovery;
use AgenticActions\Support\Packages;
use AgenticActions\Support\PackageStatus;
use AgenticActions\Surface;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/**
 * Where the package's MCP server is mounted, and why a path is not. mount(), actions:check and actions:list all read
 * unmounted(), so they cannot drift apart.
 *
 * @internal
 */
final class McpMount
{
    /**
     * The route name of the server for actions that are not tenant-scoped.
     */
    public const ROUTE = 'agentic-actions.mcp';

    /**
     * The route name of the tenant path.
     */
    public const TENANT_ROUTE = 'agentic-actions.mcp.tenant';

    /**
     * A key in the route's action array that survives route:cache and marks the package's own MCP routes.
     */
    public const MARK = 'agentic_mcp';

    /**
     * Mount each path unmounted() allows and no other route holds yet, then refresh the name lookups.
     */
    public function mount(): void
    {
        if (self::unmounted(false) === null) {
            $this->web((string) config('agentic-actions.mcp.path'), self::ROUTE, []);
        }

        if (config('agentic-actions.tenant.model') !== null && self::unmounted(true) === null) {
            $pattern = self::tenantPattern();
            $parameter = (string) config('agentic-actions.tenant.parameter');

            $this->web((string) config('agentic-actions.mcp.tenant_path'), self::TENANT_ROUTE, $pattern === null ? [] : [$parameter => $pattern]);
        }

        // A name set after the route joined the collection is missing from the lookups when an earlier booted
        // callback already refreshed them, so route() by name needs this.
        Route::getRoutes()->refreshNameLookups();

        // With OAuth on, the package's middleware joins the routes laravel/mcp and Passport registered, once.
        if (self::oauth() && (Route::has(self::ROUTE) || Route::has(self::TENANT_ROUTE))) {
            foreach ([Discovery::METADATA => Discovery::class, 'passport.authorizations.authorize' => BindConsent::class, 'passport.authorizations.approve' => BindConsent::class] as $name => $middleware) {
                $route = Route::getRoutes()->getByName($name);

                if ($route !== null && ! in_array($middleware, $route->middleware(), true)) {
                    $route->middleware($middleware);
                }
            }
        }
    }

    /**
     * Whether OAuth is on: Passport is installed and a guard mcp.middleware names has the passport driver. Config only,
     * so it holds under route:cache.
     */
    public static function oauth(): bool
    {
        return app(Packages::class)->passport() !== PackageStatus::Missing
            && array_filter(self::guards(), fn (string $guard): bool => config("auth.guards.{$guard}.driver") === 'passport') !== [];
    }

    /**
     * The package MCP route a URL on this app names, as [route name, tenant segment or null], or null: the URL starts
     * with this app's scheme, host and port, holds no fragment, and its path matches the base or the tenant route. A
     * query is ignored. Never queries.
     *
     * @return array{0: string, 1: string|null}|null
     */
    public static function path(string $url): ?array
    {
        if (! str_starts_with($url, rtrim(url('/'), '/').'/') || str_contains($url, '#')) {
            return null;
        }

        $request = Request::create($url, 'POST');

        foreach ([self::ROUTE, self::TENANT_ROUTE] as $name) {
            $route = Route::getRoutes()->getByName($name);

            if ($route !== null && $route->matches($request)) {
                $segment = $name === self::TENANT_ROUTE ? (clone $route)->bind($request)->parameter((string) config('agentic-actions.tenant.parameter')) : null;

                return [$name, is_string($segment) ? $segment : null];
            }
        }

        return null;
    }

    /**
     * The abilities the actions a path serves need, as scopes: the read ability, then the write ability, each when
     * served. Exposure keeps Destructive and External actions off MCP.
     *
     * @return list<string>
     */
    public static function scopes(bool $tenantScoped): array
    {
        $tenanted = config('agentic-actions.tenant.model') !== null;
        $served = array_filter(app(ActionRegistry::class)->on(Surface::Mcp), fn (Entry $entry): bool => ! $tenanted || $entry->tenantScoped === $tenantScoped);
        $effects = array_filter(Effect::cases(), fn (Effect $effect): bool => in_array($effect, array_column($served, 'effect'), true));

        return array_values(array_map(fn (Effect $effect): string => (string) config("agentic-actions.abilities.{$effect->value}"), $effects));
    }

    /**
     * Why the path for this scope does not mount, or null when it does. Scope true is the tenant path, false the base
     * path; with no tenant model every action is served on the base path.
     */
    public static function unmounted(bool $tenantScoped): ?string
    {
        $tenanted = config('agentic-actions.tenant.model') !== null;
        $key = $tenanted && $tenantScoped ? 'tenant_path' : 'path';
        $path = config("agentic-actions.mcp.{$key}");
        $parameter = (string) config('agentic-actions.tenant.parameter');

        return match (true) {
            ! config('agentic-actions.surfaces.mcp') => 'surfaces.mcp is off',
            self::guard() === null => 'mcp.middleware names no configured guard',
            ! is_string($path) || trim($path, '/') === '' => "agentic-actions.mcp.{$key} is null",
            $key === 'tenant_path' && ! str_contains($path, '{'.$parameter.'}') => "agentic-actions.mcp.tenant_path has no {{$parameter}}",
            ! self::serves($tenanted, $tenantScoped) => 'no action of this scope allows MCP',
            default => null,
        };
    }

    /**
     * Whether a discovered action of this scope allows MCP. Asked last, so a path that cannot mount for another reason
     * (a fresh app without a token guard) never loads the registry at boot.
     */
    private static function serves(bool $tenanted, bool $tenantScoped): bool
    {
        foreach (app(ActionRegistry::class)->on(Surface::Mcp) as $entry) {
            if (! $tenanted || $entry->tenantScoped === $tenantScoped) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first guard named in mcp.middleware that auth.guards defines, or null.
     */
    public static function guard(): ?string
    {
        foreach (self::guards() as $guard) {
            if (is_array(config("auth.guards.{$guard}"))) {
                return $guard;
            }
        }

        return null;
    }

    /**
     * Every guard an auth middleware in mcp.middleware names; a bare "auth" names the default guard.
     *
     * @return list<string>
     */
    public static function guards(): array
    {
        $guards = [];

        foreach ((array) config('agentic-actions.mcp.middleware', []) as $middleware) {
            if ($middleware === 'auth') {
                $guards[] = (string) config('auth.defaults.guard');
            } elseif (is_string($middleware) && str_starts_with($middleware, 'auth:')) {
                array_push($guards, ...explode(',', substr($middleware, 5)));
            }
        }

        return array_values(array_unique(array_filter($guards, fn (string $guard): bool => $guard !== '')));
    }

    /**
     * The tenant segment's pattern: the configured one, digits for an incrementing route key, or none.
     */
    private static function tenantPattern(): ?string
    {
        $configured = config('agentic-actions.mcp.tenant_pattern');
        $model = config('agentic-actions.tenant.model');

        if (is_string($configured) || ! is_string($model) || ! is_subclass_of($model, Model::class)) {
            return is_string($configured) ? $configured : null;
        }

        $tenant = new $model;

        return $tenant->getIncrementing() && $tenant->getRouteKeyName() === $tenant->getKeyName() ? '[1-9][0-9]*' : null;
    }

    /**
     * The tenant a route segment names, by route key; null when unknown, unreadable by the key column, or untenanted.
     */
    public static function tenant(mixed $segment): ?Model
    {
        $model = config('agentic-actions.tenant.model');

        if ($segment instanceof Model) {
            return is_string($model) && $segment instanceof $model ? $segment : null;
        }

        try {
            return is_string($segment) && $segment !== '' ? Tenants::resolve($segment) : null;
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * The limiter's budget, per person: every token and tenant path of one actor shares it. Laravel's middleware
     * priority runs authentication before the throttle, so the actor is known; the ip is a fallback that never runs.
     */
    public static function limit(Request $request): Limit
    {
        $actor = $request->user();
        $person = match (true) {
            $actor instanceof Model => $actor->getMorphClass().':'.$actor->getKey(),
            $actor !== null => $actor::class.':'.$actor->getAuthIdentifier(),
            default => 'ip:'.$request->ip(),
        };

        return Limit::perMinute((int) config('agentic-actions.mcp.per_minute', 60))->by(hash('sha256', $person));
    }

    /**
     * Mount one path in a group whose where clause reaches the GET and DELETE routes Mcp::web() adds too, so a segment
     * the pattern refuses answers 404 on every method.
     *
     * @param  array<string, string>  $where
     */
    private function web(string $path, string $name, array $where): void
    {
        $uri = trim($path, '/');

        // The mount never replaces a route: Mcp::web() adds GET, DELETE and POST at the path, so a path the app holds
        // for any of them is left alone, and actions:check names the route that holds it.
        if ($this->taken($uri)) {
            return;
        }

        Route::group(['where' => $where], function () use ($uri, $name): void {
            $oauth = self::oauth();
            $route = Mcp::web($uri, ActionsServer::class)
                ->middleware([...($oauth ? [Discovery::class] : []), ...(array) config('agentic-actions.mcp.middleware', [])])
                ->name($name);

            // laravel/mcp also runs its header middleware globally, and only for a route that lists it.
            if ($oauth) {
                $route->withoutMiddleware(AddWwwAuthenticateHeader::class);
            }

            $route->setAction([...$route->getAction(), self::MARK => true]);
        });
    }

    /**
     * Whether a route for any method Mcp::web() registers (GET, which answers HEAD, DELETE, POST) holds the URI.
     */
    private function taken(string $uri): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && array_intersect(['GET', 'HEAD', 'POST', 'DELETE'], $route->methods()) !== []) {
                return true;
            }
        }

        return false;
    }
}
