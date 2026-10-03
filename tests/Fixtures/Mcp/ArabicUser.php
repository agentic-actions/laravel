<?php

namespace Tests\Fixtures\Mcp;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person on the users table who prefers Arabic.
 */
final class ArabicUser extends Authenticatable implements HasLocalePreference
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * The person's stored language.
     */
    public function preferredLocale(): string
    {
        return 'ar';
    }
}
