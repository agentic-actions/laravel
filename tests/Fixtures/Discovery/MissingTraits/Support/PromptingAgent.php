<?php

namespace Tests\Fixtures\Discovery\MissingTraits\Support;

use Gone\Package\Promptable;

/**
 * An app's base agent that keeps a trait from a package that is gone. Loading it would stop PHP before 8.5.
 */
abstract class PromptingAgent
{
    use Promptable;
}
