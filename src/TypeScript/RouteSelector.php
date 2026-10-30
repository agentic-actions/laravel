<?php

namespace AgenticActions\TypeScript;

use AgenticActions\Action;
use AgenticActions\Http\ActionRequest;
use AgenticActions\Http\RestRegistrar;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionClass;

/**
 * Chooses which route names each action in TypeScript. Several generated mounts of one action are one
 * action: the web mount names it, so the file never points a browser at a token-only URL. A #[Rest] route is an
 * extra, exported by its route name.
 *
 * @internal
 */
final class RouteSelector
{
    /**
     * Create the selector.
     */
    public function __construct(private readonly Router $router) {}

    /**
     * The route that names each action in TypeScript, and extra hand-written routes exported by route name.
     *
     * The primary routes are keyed by action class, the extras by route name, each in registration order.
     *
     * @return array{primary: array<string, Route>, extra: array<string, Route>}
     */
    public function select(): array
    {
        $generated = [];
        $handWritten = [];
        $restful = [];
        $seen = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            // A route with several methods is listed once per method.
            if (isset($seen[spl_object_id($route)])) {
                continue;
            }

            $seen[spl_object_id($route)] = true;

            $rest = RestRegistrar::of($route) !== null;

            if ((! $rest && ! in_array('POST', $route->methods(), true)) || ($class = self::actionClass($route)) === null) {
                continue;
            }

            if ($rest) {
                $restful[$class][] = $route;
            } elseif ($route->getAction(ActionRequest::GENERATED) === true) {
                $generated[$class][] = $route;
            } else {
                $handWritten[$class][] = $route;
            }
        }

        $primary = [];
        $extra = [];

        foreach (array_unique([...array_keys($generated), ...array_keys($handWritten), ...array_keys($restful)]) as $class) {
            $others = $handWritten[$class] ?? [];

            $primary[$class] = isset($generated[$class]) ? self::webMount($generated[$class]) : array_shift($others);

            // A #[Rest] route is always exported by its route name, beside the action's own export.
            foreach ([...$others, ...($restful[$class] ?? [])] as $route) {
                $name = $route->getName();

                if ($name !== null && $name !== '') {
                    $extra[$name] = $route;
                }
            }
        }

        return ['primary' => array_filter($primary), 'extra' => $extra];
    }

    /**
     * The concrete action class a route dispatches to, or null.
     *
     * @return class-string<Action>|null
     */
    private static function actionClass(Route $route): ?string
    {
        $class = $route->getControllerClass();

        if (! is_string($class)) {
            return null;
        }

        $class = ltrim($class, '\\');

        if (! is_subclass_of($class, Action::class) || (new ReflectionClass($class))->isAbstract()) {
            return null;
        }

        return $class;
    }

    /**
     * The first generated route in the web middleware group, else the first registered.
     *
     * @param  non-empty-list<Route>  $routes
     */
    private static function webMount(array $routes): Route
    {
        foreach ($routes as $route) {
            if (in_array('web', $route->gatherMiddleware(), true)) {
                return $route;
            }
        }

        return $routes[0];
    }
}
