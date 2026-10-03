<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A team on the teams table that can be soft-deleted. The test that uses it adds the deleted_at column inside its own
 * transaction.
 */
final class TrashableTeam extends Model
{
    use SoftDeletes;

    /**
     * The table the workbench's teams live in.
     *
     * @var string
     */
    protected $table = 'teams';
}
