<?php

namespace Tests\Fixtures\Tenancy;

use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Effect;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * The actor belongs to the team through team_user. Anything but a Read to a team whose slug starts with "archived-" is
 * refused, so tests can see the effect reach membership.
 */
final class TeamMembership implements ChecksMembership
{
    /**
     * Whether the actor may enter the team for this effect.
     */
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        if (! $actor instanceof User || ! $tenant instanceof Team) {
            return false;
        }

        if ($effect !== null && $effect !== Effect::Read && str_starts_with((string) $tenant->slug, 'archived-')) {
            return false;
        }

        return $tenant->users()->whereKey($actor->getKey())->exists();
    }
}
