<?php

namespace AgenticActions\Tenancy;

use AgenticActions\Contracts\Tenancy;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Runs every pipeline step unchanged.
 *
 * @api
 */
final class NullTenancy implements Tenancy
{
    /**
     * Run the step as is.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Model $tenant, ?Authenticatable $actor, Closure $callback): mixed
    {
        return $callback();
    }
}
