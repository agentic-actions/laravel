<?php

namespace Tests\Fixtures\Streaming;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * A sub-agent: the stream agent hands it a task, and its own tools run under its own invocation id.
 */
final class HelperAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * What the sub-agent does.
     */
    public function instructions(): string
    {
        return 'You help with one task.';
    }

    /**
     * One tool that shows a row in a stream of its own.
     *
     * @return list<mixed>
     */
    public function tools(): iterable
    {
        return [new TracedTool];
    }
}
