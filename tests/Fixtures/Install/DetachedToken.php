<?php

namespace Tests\Fixtures\Install;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's token model on the "empty" connection, where no table exists.
 */
final class DetachedToken extends PersonalAccessToken
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'empty';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'personal_access_tokens';
}
