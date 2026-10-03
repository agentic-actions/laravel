<?php

namespace AgenticActions\Security;

use AgenticActions\ActionContext;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Schema\AdvertisedSchema;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\Tenancy\Tenants;
use AgenticActions\Views\Table;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Keys an agent may never be offered, at any depth of its advertised input.
 *
 * @internal
 */
final class ForbiddenKeys
{
    /** Keys an agent may be offered under the id rows of actions:check. Private, so it never becomes a preset. */
    private const ID_SHAPED = ['id', '*_id', 'uuid'];

    /**
     * The recipe every refusal about agent input names.
     */
    private const RECIPE = 'the "Strict agent schemas (no ids)" recipe (docs/recipes.md#strict-agent-schemas-no-ids)';

    /**
     * Each route's parameters by route name or URI, memoized per request for a class and a route table.
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $routes = [];

    /**
     * Dot paths of advertised input keys an agent may never see, at any depth.
     *
     * @param  array<string, mixed>  $node  the advertised object node
     * @param  list<string>  $routeParameters  every parameter of every route whose controller is the action
     * @return list<string>
     */
    public function inInput(array $node, array $routeParameters): array
    {
        return array_keys($this->inputReasons($node, ['' => $routeParameters]));
    }

    /**
     * Dot paths of output keys matching agents.forbidden_output_keys.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function inOutput(array $node): array
    {
        $patterns = $this->patterns('agents.forbidden_output_keys');

        return array_values(array_filter(
            self::paths($node),
            fn (string $path): bool => $this->matching(self::last($path), $patterns) !== null,
        ));
    }

    /**
     * Dot paths of advertised keys that look like ids.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function idShaped(array $node): array
    {
        return array_values(array_filter(
            self::paths($node),
            fn (string $path): bool => $this->matching(self::last($path), self::ID_SHAPED) !== null,
        ));
    }

    /**
     * The route parameters of every route whose controller is the class, memoized per request.
     *
     * @return list<string>
     */
    public function routeParameters(string $class): array
    {
        return array_values(array_unique(array_merge([], ...array_values($this->routesOf($class)))));
    }

    /**
     * Whether the catalog may offer the entry: no forbidden input or output key and no binary field. In tests and
     * locally a violation throws MisconfiguredExposure; elsewhere it is reported at most once an hour per class and
     * the tool is left out.
     */
    public function advertisable(Entry $entry, ActionContext $context): bool
    {
        $action = $entry->action();
        $input = app(AdvertisedSchema::class)->node($action, $context);
        $violations = [];

        foreach ($this->inputReasons($input, $this->routesOf($entry->class)) as $path => $reason) {
            $violations[] = "{$entry->class}: agents cannot be offered input [{$path}]: it {$reason}. The tool is left out. Remove the key from schema(), or follow ".self::RECIPE.': agentSchema() plus fromAgent().';
        }

        try {
            $output = app(SchemaReader::class)->output($action);
            $read = $action instanceof Dataset ? $action->columnsRead() : [];
        } catch (InvalidArgumentException $exception) {
            // A table whose columns() cannot be shown is left out alone; actions:check names it.
            [$output, $read] = [[], []];
            $violations[] = "{$entry->class}: agents cannot be offered it: ".$exception->getMessage().' The tool is left out.';
        }

        // A table's own keys (columns, label, chart…) are the package's; only its columns are data.
        foreach ($this->inOutput($entry->shows() ? Table::rowNode($output) : $output) as $path) {
            $violations[] = "{$entry->class}: agents cannot receive output [{$path}]: it matches agents.forbidden_output_keys. The tool is left out. ".($entry->shows() ? 'Remove the column from columns().' : 'Remove the key from outputSchema().');
        }

        // A dataset's values come from the columns its declarations read, whatever names show them.
        foreach ($this->inOutput(['type' => 'object', 'properties' => array_fill_keys($read, ['type' => 'string'])]) as $path) {
            $violations[] = "{$entry->class}: agents cannot receive the column [{$path}] a dimension or measure reads: it matches agents.forbidden_output_keys. The tool is left out.";
        }

        foreach (self::binaryPaths($input) as $path) {
            $violations[] = "{$entry->class}: agents cannot send the file field [{$path}]. The tool is left out. Give agents an agentSchema() without it, following ".self::RECIPE.'.';
        }

        if ($violations === []) {
            return true;
        }

        $exception = MisconfiguredExposure::withErrors($violations);

        if (app()->runningUnitTests() || app()->isLocal()) {
            throw $exception;
        }

        if (Cache::add('agentic-actions:forbidden-keys:'.$entry->class, true, 3600)) {
            report($exception);
        }

        return false;
    }

    /**
     * Why each forbidden input path is forbidden, keyed by dot path.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, list<string>>  $routes  route name or URI => parameters
     * @return array<string, string>
     */
    private function inputReasons(array $node, array $routes): array
    {
        $patterns = $this->patterns('agents.forbidden_keys');
        $parameter = config('agentic-actions.tenant.parameter');
        $foreignKey = Tenants::foreignKey();
        $reasons = [];

        foreach (self::paths($node) as $path) {
            $name = self::last($path);

            $reason = match (true) {
                ($pattern = $this->matching($name, $patterns)) !== null => "matches agents.forbidden_keys pattern \"{$pattern}\"",
                ($route = $this->routeNaming($name, $routes)) !== null => $route === '' ? 'is a route parameter of the action' : "is a route parameter of {$route}",
                is_string($parameter) && $parameter !== '' && strcasecmp($name, $parameter) === 0 => 'is the tenant parameter',
                $foreignKey !== null && strcasecmp($name, $foreignKey) === 0 => "is the tenant model's foreign key",
                default => null,
            };

            if ($reason !== null) {
                $reasons[$path] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * The route (by name or URI) one of whose parameters the key is, or null.
     *
     * @param  array<string, list<string>>  $routes
     */
    private function routeNaming(string $name, array $routes): ?string
    {
        foreach ($routes as $route => $parameters) {
            foreach ($parameters as $parameter) {
                if (strcasecmp($name, $parameter) === 0) {
                    return (string) $route;
                }
            }
        }

        return null;
    }

    /**
     * The routes whose controller is the class, as route name or URI => parameters.
     *
     * @return array<string, list<string>>
     */
    private function routesOf(string $class): array
    {
        $routes = Route::getRoutes();
        $key = spl_object_id($routes).':'.count($routes->getRoutes()).':'.$class;

        if (isset($this->routes[$key])) {
            return $this->routes[$key];
        }

        $found = [];

        foreach ($routes->getRoutes() as $route) {
            if ($route instanceof RoutingRoute && $route->getControllerClass() === $class) {
                $found[$route->getName() ?? $route->uri()] = $route->parameterNames();
            }
        }

        return $this->routes[$key] = $found;
    }

    /**
     * The first pattern the key name matches, as a case-insensitive glob, or null.
     *
     * @param  list<string>  $patterns
     */
    private function matching(string $name, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (Str::is(strtolower($pattern), strtolower($name))) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * A configured list of glob patterns.
     *
     * @return list<string>
     */
    private function patterns(string $key): array
    {
        return array_values(array_map(strval(...), (array) config("agentic-actions.{$key}", [])));
    }

    /**
     * Every property path in a node, at any depth; list items appear as "*".
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function paths(array $node): array
    {
        return array_keys(self::nodes($node, ''));
    }

    /**
     * Dot paths of binary (file) fields, including lists of files.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function binaryPaths(array $node): array
    {
        $paths = [];

        foreach (self::nodes($node, '') as $path => $property) {
            $items = is_array($property['items'] ?? null) ? $property['items'] : [];

            if (($property['format'] ?? null) === 'binary' || ($items['format'] ?? null) === 'binary') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Every property node under a node, keyed by dot path: object properties, list items' properties (as "*") and
     * anyOf branches' properties.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, array<string, mixed>>
     */
    private static function nodes(array $node, string $prefix): array
    {
        $nodes = [];

        foreach ((array) ($node['properties'] ?? []) as $key => $property) {
            if (! is_array($property)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $nodes[$path] = $property;
            $nodes += self::within($property, $path);
        }

        return $nodes;
    }

    /**
     * The property nodes inside one value node: its own properties, its list items' and its anyOf branches'.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, array<string, mixed>>
     */
    private static function within(array $node, string $path): array
    {
        $nodes = self::nodes($node, $path);

        if (is_array($node['items'] ?? null)) {
            $nodes += self::within($node['items'], "{$path}.*");
        }

        foreach ((array) ($node['anyOf'] ?? []) as $branch) {
            if (is_array($branch)) {
                $nodes += self::within($branch, $path);
            }
        }

        return $nodes;
    }

    /**
     * The last segment of a dot path.
     */
    private static function last(string $path): string
    {
        return Str::afterLast($path, '.');
    }
}
