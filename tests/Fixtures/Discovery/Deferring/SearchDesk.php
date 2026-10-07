<?php

namespace Tests\Fixtures\Discovery\Deferring;

use AgenticActions\Attributes\DeferToolset;

/**
 * An agent that loads no toolset and finds the support toolset through tool search.
 */
#[DeferToolset('support')]
final class SearchDesk
{
    //
}
