<?php

namespace AgenticActions\Contracts;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Switches a tenant's state on around every pipeline step.
 *
 * @api
 */
interface Tenancy
{
    /**
     * Run a pipeline step with the tenant's state switched on.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed;
}
