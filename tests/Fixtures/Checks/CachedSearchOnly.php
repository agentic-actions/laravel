<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Attributes\DeferToolset;
use Laravel\Ai\Attributes\CacheToolDefinitions;

/**
 * An agent that loads no toolset, finds the default toolset through tool search, and caches its tool definitions.
 */
#[DeferToolset]
#[CacheToolDefinitions]
final class CachedSearchOnly
{
    //
}
