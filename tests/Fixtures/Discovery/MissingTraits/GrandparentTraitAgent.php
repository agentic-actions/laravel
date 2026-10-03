<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;
use Tests\Fixtures\Discovery\MissingTraits\Support\SpecialistAgent as Specialist;

/**
 * An agent whose grandparent uses a trait from a package that is gone.
 */
#[UseToolset]
final class GrandparentTraitAgent extends Specialist
{
    //
}
