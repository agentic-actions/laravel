<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * Names a toolset but never says whom its tools act for.
 */
#[UseToolset]
final class NoContextAgent implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help someone.';
    }
}
