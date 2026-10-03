<?php

namespace Tests\Fixtures\Install;

use Illuminate\Foundation\Auth\User;
use Laravel\Passport\HasApiTokens;

/**
 * A user model on Passport's trait alone, as an app without Sanctum has it.
 */
final class PassportUser extends User
{
    use HasApiTokens;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';
}
