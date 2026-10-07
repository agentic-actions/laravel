<?php

namespace Tests\Fixtures\Discovery\Deferring;

use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;

/**
 * An agent that loads the default toolset and finds the support toolset through tool search.
 */
#[UseToolset]
#[DeferToolset('support')]
final class DeferringDesk
{
    //
}
