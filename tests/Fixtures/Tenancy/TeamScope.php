<?php

namespace Tests\Fixtures\Tenancy;

use AgenticActions\Contracts\ScopesToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Workbench\App\Models\Post;

/**
 * Posts belong to a team by team_id. Any other model throws: loud, never unscoped.
 */
final class TeamScope implements ScopesToTenant
{
    /**
     * Scope a query to the team.
     */
    public function __invoke(Builder $query, Model $tenant): Builder
    {
        if ($query->getModel() instanceof Post) {
            return $query->where('team_id', $tenant->getKey());
        }

        throw new LogicException('TeamScope cannot scope '.$query->getModel()::class.'.');
    }
}
