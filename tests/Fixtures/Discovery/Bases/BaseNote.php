<?php

namespace Tests\Fixtures\Discovery\Bases;

use AgenticActions\Action;
use AgenticActions\Effect;

/**
 * An app's own abstract base action: never an action itself.
 */
abstract class BaseNote extends Action
{
    protected ?Effect $effect = Effect::Write;
}
