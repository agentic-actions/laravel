<?php

namespace Tests\Fixtures\Discovery\Broken;

use AgenticActions\Action;

/**
 * An action that implements an interface nothing declares, like an agent left behind by composer install --no-dev.
 * Loading it throws from the autoloader.
 */
final class Unloadable extends Action implements MissingContract
{
    //
}
