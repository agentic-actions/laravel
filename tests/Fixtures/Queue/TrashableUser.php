<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;

/**
 * A person on the users table who can be soft-deleted. The test that uses it adds the deleted_at column inside its
 * own transaction.
 */
final class TrashableUser extends User
{
    use SoftDeletes;

    /**
     * The table the workbench's people live in.
     *
     * @var string
     */
    protected $table = 'users';
}
