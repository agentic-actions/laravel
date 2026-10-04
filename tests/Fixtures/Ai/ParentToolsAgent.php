<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Attributes\UseToolset;

/**
 * Its own tools() starts from its parent's, which come from InteractsWithActions.
 */
#[UseToolset]
final class ParentToolsAgent extends NotesAgentBase
{
    /**
     * The parent's tools, where the agent would add its own.
     */
    public function tools(): iterable
    {
        return [...parent::tools()];
    }
}
