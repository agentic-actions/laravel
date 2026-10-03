<?php

namespace Tests\Fixtures\Mcp;

use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Effect;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Anyone in team_user belongs to the team, whatever their model class. It counts the questions it is asked.
 */
final class McpMembership implements ChecksMembership
{
    /**
     * How many times membership was asked, and with which effects.
     *
     * @var list<Effect|null>
     */
    public static array $asked = [];

    /**
     * Whether the actor may enter the team.
     */
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        self::$asked[] = $effect;

        return $tenant->getConnection()->table('team_user')
            ->where('team_id', $tenant->getKey())
            ->where('user_id', $actor->getAuthIdentifier())
            ->exists();
    }
}
