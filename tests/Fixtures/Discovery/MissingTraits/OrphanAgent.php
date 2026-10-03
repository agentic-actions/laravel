<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;
use Gone\Package\Agent;

/**
 * An agent whose parent class is gone. Loading it throws, which the scan catches.
 */
#[UseToolset]
final class OrphanAgent extends Agent
{
    //
}
