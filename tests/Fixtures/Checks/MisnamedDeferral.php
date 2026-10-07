<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;

/**
 * An agent that loads the default toolset and also defers it, beside an archive toolset no action joins.
 */
#[UseToolset]
#[DeferToolset('default', 'archive')]
final class MisnamedDeferral
{
    //
}
