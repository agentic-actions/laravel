<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Database\Eloquent\Model;

/**
 * The workbench's teams, routed by a column the database refuses to read, as an integer key column refuses a word.
 */
final class KeyRefusingTeam extends Model
{
    protected $table = 'teams';

    /**
     * A route key column the table lacks, so every lookup by route key throws a QueryException.
     */
    public function getRouteKeyName(): string
    {
        return 'no_such_column';
    }
}
