<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;
use Tests\Fixtures\Discovery\MissingTraits\Support\LoopsOne;

/**
 * An agent whose trait uses itself through another trait.
 */
#[UseToolset]
final class LoopingTraitAgent
{
    use LoopsOne;
}
