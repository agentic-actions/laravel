<?php

namespace Tests\Fixtures\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Promptable;

/**
 * A sub-agent that acts as a tool named like the create-note action.
 */
final class NoteWriter implements Agent, CanActAsTool
{
    use Promptable;

    /**
     * What the sub-agent does.
     */
    public function instructions(): string
    {
        return 'You write notes.';
    }

    /**
     * The colliding tool name.
     */
    public function name(): string
    {
        return 'create-note';
    }

    /**
     * What the tool does.
     */
    public function description(): string
    {
        return 'Delegate note writing.';
    }
}
