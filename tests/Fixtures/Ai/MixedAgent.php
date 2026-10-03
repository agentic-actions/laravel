<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * Its own tools() adds a hand-written tool whose name collides with an action tool.
 */
#[UseToolset]
final class MixedAgent implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    /**
     * Build the agent for one author.
     */
    public function __construct(public User $user) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author.';
    }

    /**
     * A hand-written tool, then the action tools.
     */
    public function tools(): iterable
    {
        return [new CreateNoteTool, ...$this->actionTools()];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
