<?php

namespace AgenticActions\Tenancy;

use AgenticActions\ActionsManager;
use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Contracts\ScopesToTenant;
use AgenticActions\Effect;
use AgenticActions\MissingContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Tenants are bound by primary key and resolved by route key.
 *
 * @internal
 */
final class Tenants
{
    /**
     * Resolve a route segment to a tenant by the tenant model's route key.
     */
    public static function resolve(int|string $key): ?Model
    {
        $model = self::model();

        if ($model === null) {
            return null;
        }

        $tenant = $model->resolveRouteBinding($key);

        return $tenant instanceof Model ? $tenant : null;
    }

    /**
     * Whether the actor may enter the tenant for this effect: the tenant.membership class, else the membershipUsing()
     * closure, else false.
     */
    public static function member(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        $class = config('agentic-actions.tenant.membership');

        if (is_string($class) && $class !== '') {
            $membership = app($class);

            if (! $membership instanceof ChecksMembership) {
                throw new LogicException("[{$class}] must implement ".ChecksMembership::class.'.');
            }

            return $membership($actor, $tenant, $effect) === true;
        }

        $closure = app(ActionsManager::class)->membership();

        return $closure !== null && $closure($actor, $tenant, $effect) === true;
    }

    /**
     * Scope a query: the tenant.scope class, else the scopeUsing() closure, else MissingContext::scope(). The scope's
     * own conditions are one group, so a condition added after them narrows its rows.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     *
     * @throws MissingContext
     */
    public static function scope(Builder $query, Model $tenant): Builder
    {
        $before = count($query->getQuery()->wheres);
        $scoped = self::scoper()($query, $tenant);

        return self::grouped($scoped, $scoped === $query ? $before : 0);
    }

    /**
     * The tenant model's foreign key, e.g. "team_id", or null without a tenant model.
     */
    public static function foreignKey(): ?string
    {
        return self::model()?->getForeignKey();
    }

    /**
     * Whether a membership class or closure is configured.
     */
    public static function hasMembership(): bool
    {
        $class = config('agentic-actions.tenant.membership');

        return (is_string($class) && $class !== '') || app(ActionsManager::class)->membership() !== null;
    }

    /**
     * Whether a scope class or closure is configured.
     */
    public static function hasScope(): bool
    {
        $class = config('agentic-actions.tenant.scope');

        return (is_string($class) && $class !== '') || app(ActionsManager::class)->scope() !== null;
    }

    /**
     * The tenant.scope class, else the scopeUsing() closure.
     *
     * @return callable(Builder<Model>, Model): Builder<Model>
     *
     * @throws MissingContext
     */
    private static function scoper(): callable
    {
        $class = config('agentic-actions.tenant.scope');

        if (is_string($class) && $class !== '') {
            $scope = app($class);

            if (! $scope instanceof ScopesToTenant) {
                throw new LogicException("[{$class}] must implement ".ScopesToTenant::class.'.');
            }

            return $scope;
        }

        return app(ActionsManager::class)->scope() ?? throw MissingContext::scope();
    }

    /**
     * The scope's own conditions, from the given one on, as one group joined by "and", whatever they hold: an orWhere,
     * or an or inside a raw condition. The bindings keep their order, since the group compiles in place.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function grouped(Builder $query, int $from): Builder
    {
        $base = $query->getQuery();
        $own = array_slice($base->wheres, $from);

        if ($own === []) {
            return $query;
        }

        $group = $base->forNestedWhere();
        $group->wheres = $own;
        $base->wheres = [...array_slice($base->wheres, 0, $from), ['type' => 'Nested', 'query' => $group, 'boolean' => 'and']];

        return $query;
    }

    /**
     * A fresh instance of the configured tenant model, or null without one.
     */
    private static function model(): ?Model
    {
        $class = config('agentic-actions.tenant.model');

        if (! is_string($class) || $class === '') {
            return null;
        }

        $model = new $class;

        if (! $model instanceof Model) {
            throw new LogicException("[{$class}] must be an Eloquent model.");
        }

        return $model;
    }
}
