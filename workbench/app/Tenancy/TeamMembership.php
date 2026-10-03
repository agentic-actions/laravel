<?php

namespace Workbench\App\Tenancy;

use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Effect;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * An author may act in a team they belong to.
 */
final class TeamMembership implements ChecksMembership
{
    /**
     * Whether the actor belongs to the team.
     */
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        if (! $actor instanceof User || ! $tenant instanceof Team) {
            return false;
        }

        return $tenant->users()->whereKey($actor->getKey())->exists();
    }
}
