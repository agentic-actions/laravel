<?php

namespace AgenticActions\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * Reads what the current credential grants. Bind your own implementation in a service provider for a JWT, API-key or
 * other token guard.
 *
 * @api
 */
interface ReadsTokenGrants
{
    /**
     * The abilities the current credential grants. Null means a session: full access, as Laravel's own session has.
     * An empty list means nothing: every action reads as not found.
     *
     * @return list<string>|null
     */
    public function grants(?Authenticatable $actor, Guard $guard): ?array;
}
