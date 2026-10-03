<?php

namespace Tests\Fixtures\Discovery\Agents;

use AgenticActions\Attributes\UseToolset;

/**
 * An agent that receives the support toolset.
 */
#[UseToolset('support')]
final class SupportDesk
{
    //
}
