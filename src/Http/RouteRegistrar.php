<?php

namespace AgenticActions\Http;

use AgenticActions\Action;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Effect;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Feed\ChangesController;
use AgenticActions\Surface;
use AgenticActions\Views\RefreshView;
use Closure;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/**
 * Registers one POST route per web-exposed action inside whatever group calls it (GET, HEAD and POST for a Read on a
 * mount with reads: 'get'), the change feed's route when feed.enabled is on, and the tables' refresh route. The app
 * decides where routes live and which middleware guards them; the package decides only which exist. Every call still
 * re-reads the class, so a route that outlives its #[Expose] answers not found.
 *
 * @internal
 */
final class RouteRegistrar
{
    /**
     * Register the generated routes in the current group.
     *
     * @param  bool|null  $tenant  true: tenant-scoped actions only; false: the others; null: all
     * @param  'post'|'get'  $reads  'get' also answers GET and HEAD on a Read's route
     * @param  (Closure(Entry): bool)|null  $only  mount only the actions it returns true for
     * @param  (Closure(Entry): bool)|null  $except  leave out the actions it returns true for
     *
     * @throws MisconfiguredExposure for a hand-written twin registered earlier, or an action named like the feed route
     *                               or the refresh route
     * @throws InvalidArgumentException for a $reads other than 'post' or 'get'
     */
    public function register(?bool $tenant = null, string $reads = 'post', ?Closure $only = null, ?Closure $except = null): void
    {
        if (! in_array($reads, ['post', 'get'], true)) {
            throw new InvalidArgumentException("Actions::routes() takes reads: 'post' or 'get', not [{$reads}].");
        }

        if (! config('agentic-actions.surfaces.web')) {
            return;   // checked again by the Runner on every call
        }

        $handWritten = $this->handWrittenRoutes();

        Route::prefix((string) config('agentic-actions.routes.path'))
            ->name((string) config('agentic-actions.routes.name'))
            ->group(function () use ($tenant, $reads, $only, $except, $handWritten): void {
                foreach (app(ActionRegistry::class)->on(Surface::Http) as $candidate) {
                    $entry = $this->live($candidate);

                    if ($entry === null || ! self::mounts($entry, $tenant, $only, $except)) {
                        continue;
                    }

                    if (in_array($entry->segment(), [ChangesController::SEGMENT, RefreshView::SEGMENT], true)) {
                        $route = $entry->segment() === ChangesController::SEGMENT ? 'the change feed\'s' : 'the tables\' refresh';

                        throw MisconfiguredExposure::withErrors(["{$entry->class}: the name [{$entry->segment()}] is {$route} route; set another \$name."]);
                    }

                    $this->refuseHandWrittenTwin($entry, $handWritten);

                    // A GET skips the CSRF check, so it is opt-in per mount and only ever for a Read; the route keeps POST.
                    $methods = $reads === 'get' && $entry->effect === Effect::Read ? ['GET', 'POST'] : ['POST'];

                    $route = Route::match($methods, $entry->segment(), $entry->class)
                        ->middleware(HandlePrecognitiveRequests::class)
                        ->name($entry->segment());

                    $route->setAction([...$route->getAction(), ActionRequest::GENERATED => true]);
                }

                if (config('agentic-actions.feed.enabled')) {
                    Route::post(ChangesController::SEGMENT, ChangesController::class)->name(ChangesController::SEGMENT);
                }

                $refresh = Route::post(RefreshView::SEGMENT.'/{view}', RefreshView::class)->name(RefreshView::SEGMENT);
                $refresh->setAction([...$refresh->getAction(), RefreshView::TENANT => $tenant]);
            });
    }

    /**
     * Whether a mount serves the action: open on the web, of the tenant split asked for, and kept by the filters.
     *
     * @param  (Closure(Entry): bool)|null  $only
     * @param  (Closure(Entry): bool)|null  $except
     */
    public static function mounts(Entry $entry, ?bool $tenant, ?Closure $only, ?Closure $except): bool
    {
        return $entry->allows(Surface::Http)
            && ($tenant === null || $entry->tenantScoped === $tenant)
            && ($only === null || $only($entry) === true)
            && ($except === null || $except($entry) !== true);
    }

    /**
     * Refuse an action that a route registered earlier, by hand, already serves: two doors to one class, one of
     * which skips the class's own #[Expose].
     *
     * @param  array<string, RoutingRoute>  $handWritten
     *
     * @throws MisconfiguredExposure
     */
    private function refuseHandWrittenTwin(Entry $entry, array $handWritten): void
    {
        if (! isset($handWritten[$entry->class])) {
            return;
        }

        $route = $handWritten[$entry->class];
        $label = implode('|', $route->methods()).' '.$route->uri();

        throw MisconfiguredExposure::withErrors([
            "{$entry->class}: http: the hand-written route [{$label}] already serves this class, so Actions::routes() would add a twin. Remove that route, or leave web out of #[Expose].",
        ]);
    }

    /**
     * The first hand-written route of each controller class registered so far. Generated routes never count, so
     * several mounts coexist.
     *
     * @return array<string, RoutingRoute>
     */
    private function handWrittenRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $class = $route->getControllerClass();

            if (is_string($class) && $route->getAction(ActionRequest::GENERATED) !== true) {
                $routes[ltrim($class, '\\')] ??= $route;
            }
        }

        return $routes;
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
