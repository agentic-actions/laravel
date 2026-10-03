<?php

namespace Tests\Fixtures\Discovery\MissingTraits\Support;

use Gone\Package\Conversational as Talks;

/**
 * An app's own trait that uses a trait from a package that is gone.
 */
trait Remembers
{
    use Talks;
}
