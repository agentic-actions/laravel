<?php

namespace Tests\Fixtures\Discovery\MissingTraits\Support;

use Illuminate\Support\Traits\Conditionable;

/**
 * An app's own trait that uses a trait that exists.
 */
trait Keeps
{
    use Conditionable;
}
