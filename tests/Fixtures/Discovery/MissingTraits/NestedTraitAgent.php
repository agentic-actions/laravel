<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;

/**
 * An agent whose own trait uses a trait from a package that is gone.
 */
#[UseToolset]
final class NestedTraitAgent
{
    use Support\Remembers;
}
