<?php

namespace Tests\Fixtures\Discovery\MissingTraits;

use AgenticActions\Attributes\UseToolset;

/**
 * An agent whose parent's traits, and their traits, all exist.
 */
#[UseToolset('support')]
final class SoundAgent extends Support\SoundBase
{
    //
}
