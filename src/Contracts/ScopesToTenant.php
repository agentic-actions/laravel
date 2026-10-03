<?php

namespace AgenticActions\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a query to one tenant for ActionContext::find().
 *
 * @api
 */
interface ScopesToTenant
{
    /**
     * Scope a query to the tenant. Throw for any (model, tenant) pair you do not handle: loud, never unscoped.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function __invoke(Builder $query, Model $tenant): Builder;
}
