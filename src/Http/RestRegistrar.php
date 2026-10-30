<?php

namespace AgenticActions\Http;

use AgenticActions\Action;
use AgenticActions\Attributes\Rest;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Effect;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Schema\SchemaReader;
use AgenticActions\Surface;
use Closure;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Throwable;

/**
 * Registers the #[Rest] routes of the web-exposed actions inside whatever group calls it, named {group name}rest.{action}
 * (rest.{action}.2 and on for an alias). Each one is a generated route, so it runs the pipeline the generated route
 * runs, Precognition included, re-reading the class on every call; the route action carries what the responder and
 * the context read.
 *
 * @internal
 */
final class RestRegistrar
{
    /**
     * The route-action key of a #[Rest] route: its bind map, created flag and location. It survives route:cache.
     */
    public const KEY = 'agentic_rest';

    /**
     * The methods #[Rest] may name.
     */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Register the #[Rest] routes in the current group.
     *
     * @param  bool|null  $tenant  true: tenant-scoped actions only; false: the others; null: all
     * @param  (Closure(Entry): bool)|null  $only
     * @param  (Closure(Entry): bool)|null  $except
     *
     * @throws MisconfiguredExposure for a method #[Rest] does not take, or two routes on one method and path
     */
    public function register(?bool $tenant = null, ?Closure $only = null, ?Closure $except = null): void
    {
        if (! config('agentic-actions.surfaces.web')) {
            return;   // checked again by the Runner on every call
        }

        $taken = [];

        foreach (app(ActionRegistry::class)->on(Surface::Http) as $candidate) {
            $entry = $this->live($candidate);

            if ($entry === null || ! RouteRegistrar::mounts($entry, $tenant, $only, $except)) {
                continue;
            }

            foreach (self::declared($entry->class) as $index => $rest) {
                $verb = $rest->verb();
                $label = "{$verb} {$rest->uri()}";

                if (! in_array($verb, self::METHODS, true)) {
                    throw MisconfiguredExposure::withErrors(["{$entry->class}: #[Rest] takes GET, POST, PUT, PATCH or DELETE, not [{$rest->method}]."]);
                }

                if (isset($taken[$label])) {
                    throw MisconfiguredExposure::withErrors(["{$entry->class}: #[Rest('{$verb}', '{$rest->uri()}')] is already {$taken[$label]}'s route in this group."]);
                }

                $taken[$label] = $entry->class;

                $route = Route::match([$verb], $rest->uri(), $entry->class)
                    ->middleware(HandlePrecognitiveRequests::class)
                    ->name('rest.'.$entry->segment().($index === 0 ? '' : '.'.($index + 1)));

                $route->setAction([...$route->getAction(), ActionRequest::GENERATED => true, self::KEY => [
                    'bind' => $rest->bind,
                    'created' => $rest->created,
                    'location' => $rest->location,
                    'path' => $rest->uri(),
                ]]);
            }
        }
    }

    /**
     * One row per registered #[Rest] route, in registration order: what an OpenAPI document needs to describe it.
     *
     * @return list<array{name: string|null, method: string, path: string, action: string, class: class-string<Action>, description: string, effect: string|null, ability: string|null, tenant_scoped: bool, parameters: list<array{name: string, input: string|null, required: bool, schema: array<string, mixed>}>, input: array<string, mixed>, input_in: 'query'|'body', output: array<string, mixed>, status: int}>
     */
    public function describe(): array
    {
        $reader = app(SchemaReader::class);
        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $rest = self::of($route);
            $class = ltrim((string) $route->getControllerClass(), '\\');

            if ($rest === null || ! is_subclass_of($class, Action::class)) {
                continue;
            }

            $entry = ClassExposure::of($class);
            $action = $entry->action();
            $input = $reader->input($action);
            $output = $reader->output($action);
            $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
            $parameters = [];
            $bound = [];

            foreach ($route->parameterNames() as $parameter) {
                $key = $rest['bind'][$parameter] ?? $parameter;
                $own = array_key_exists($key, $properties);

                if ($own) {
                    $bound[] = $key;
                }

                $parameters[] = [
                    'name' => $parameter,
                    'input' => $own ? $key : null,
                    'required' => ! str_contains($route->uri(), '{'.$parameter.'?}'),
                    'schema' => $own && is_array($properties[$key]) ? $properties[$key] : ['type' => 'string'],
                ];
            }

            $verb = array_values(array_diff($route->methods(), ['HEAD']))[0] ?? 'GET';

            $rows[] = [
                'name' => $route->getName(),
                'method' => $verb,
                'path' => '/'.ltrim($route->uri(), '/'),
                'action' => $entry->name,
                'class' => $entry->class,
                'description' => $entry->description,
                'effect' => $entry->effect?->value,
                'ability' => $entry->effect === null ? null : (string) config('agentic-actions.abilities.'.$entry->effect->value),
                'tenant_scoped' => $entry->tenantScoped,
                'parameters' => $parameters,
                'input' => self::without($input, $bound),
                'input_in' => $verb === 'GET' ? 'query' : 'body',
                'output' => $output,
                'status' => self::status($entry, $rest, $output),
            ];
        }

        return $rows;
    }

    /**
     * The status a success answers with: 201 for a create, 204 for an action that declares no output, else 200. A
     * Read always answers 200 with its JSON.
     *
     * @param  array{created: bool}  $rest
     * @param  array<string, mixed>  $output  the serialized output node
     */
    public static function status(Entry $entry, array $rest, array $output): int
    {
        return match (true) {
            $rest['created'] => 201,
            $entry->effect !== Effect::Read && ($output['properties'] ?? []) === [] => 204,
            default => 200,
        };
    }

    /**
     * An object node without some properties.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function without(array $node, array $keys): array
    {
        if (is_array($node['properties'] ?? null)) {
            $node['properties'] = array_diff_key($node['properties'], array_flip($keys));
        }

        if (is_array($node['required'] ?? null)) {
            $node['required'] = array_values(array_diff($node['required'], $keys));
        }

        return $node;
    }

    /**
     * The #[Rest] attributes the class itself declares, in order.
     *
     * @param  class-string<Action>  $class
     * @return list<Rest>
     */
    public static function declared(string $class): array
    {
        return array_map(
            fn ($attribute): Rest => $attribute->newInstance(),
            (new ReflectionClass($class))->getAttributes(Rest::class),
        );
    }

    /**
     * What a #[Rest] route carries, or null for any other route.
     *
     * @return array{bind: array<string, string>, created: bool, location: string|null, path: string}|null
     */
    public static function of(?RoutingRoute $route): ?array
    {
        $rest = $route?->getAction(self::KEY);

        return is_array($rest) ? $rest : null;
    }

    /**
     * What the class declares now, or null when a stale manifest names a class that is gone.
     */
    private function live(Entry $candidate): ?Entry
    {
        try {
            return is_subclass_of($candidate->class, Action::class) ? ClassExposure::of($candidate->class) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
