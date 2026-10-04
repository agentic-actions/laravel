<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A class started from a blank agent stub with #[UseToolset] added, but not InteractsWithActions.
 */
#[UseToolset]
final class TraitlessToolsetAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author manage their notes.';
    }

    /**
     * No tools.
     */
    public function tools(): iterable
    {
        return [];
    }
}
