<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Contracts\ReadsTokenGrants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * An app's own token reader, for an API-key guard: actions:list names it instead of describing each guard.
 */
final class HeaderTokenReader implements ReadsTokenGrants
{
    /**
     * Every API key may read.
     *
     * @return list<string>|null
     */
    public function grants(?Authenticatable $actor, Guard $guard): ?array
    {
        return ['actions:read'];
    }
}
