<?php

namespace AgenticActions\Tenancy;

use AgenticActions\Contracts\Tenancy;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

/**
 * Swaps spatie/laravel-permission's team to the tenant for the step, then restores it.
 *
 * @api
 */
final class SpatieTeams implements Tenancy
{
    /**
     * Set the permission team to the tenant's key and forget the actor's cached roles and permissions, on entry and in
     * finally.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed
    {
        if ($tenant === null) {
            return $callback();
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($tenant->getKey());
        $this->forgetCachedRelations($actor);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $this->forgetCachedRelations($actor);
        }
    }

    /**
     * Unset the actor's loaded roles and permissions, which belong to the previous team.
     */
    private function forgetCachedRelations(?Authenticatable $actor): void
    {
        if ($actor instanceof Model) {
            $actor->unsetRelation('roles');
            $actor->unsetRelation('permissions');
        }
    }
}
