<?php

namespace Tests\Fixtures\Discovery\MissingTraits\Support;

/**
 * The other half of the LoopsOne cycle.
 */
trait LoopsTwo
{
    use LoopsOne;
}
