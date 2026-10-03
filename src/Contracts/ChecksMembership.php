<?php

namespace AgenticActions\Contracts;

use AgenticActions\Effect;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether an actor may enter a tenant.
 *
 * @api
 */
interface ChecksMembership
{
    /**
     * Whether the actor may enter the tenant for this effect. Side-effect free: it runs on every surface before anything
     * reads the tenant. $effect is null when only listing.
     */
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool;
}
