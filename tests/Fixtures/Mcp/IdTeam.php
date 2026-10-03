<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Database\Eloquent\Model;

/**
 * The workbench's teams, routed by their incrementing id.
 */
final class IdTeam extends Model
{
    protected $table = 'teams';
}
