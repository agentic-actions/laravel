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
 * The action tools plus a sub-agent that laravel/ai turns into a tool named create-note.
 */
#[UseToolset]
final class SubAgentMixedAgent implements Agent, HasTools
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
     * The action tools, then the sub-agent.
     */
    public function tools(): iterable
    {
        return [...$this->actionTools(), new NoteWriter];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
