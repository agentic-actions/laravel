<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;

/**
 * An agent whose parent uses a trait from a package that is gone.
 */
#[UseToolset]
final class ParentTraitAgent extends Support\PromptingAgent
{
    //
}
