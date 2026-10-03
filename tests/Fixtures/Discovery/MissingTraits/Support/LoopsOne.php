<?php

namespace Tests\Fixtures\Discovery\MissingTraits\Support;

/**
 * A trait that uses itself through LoopsTwo, which PHP never finds.
 */
trait LoopsOne
{
    use LoopsTwo;
}
