<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Workbench\App\Models\User;

/**
 * The action tools plus a tool-search group that holds a hand-written create-note.
 */
#[UseToolset]
final class SearchMixedAgent implements Agent, HasTools
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
     * The action tools, then a tool-search group.
     */
    public function tools(): iterable
    {
        return [...$this->actionTools(), new ToolSearch([new CreateNoteTool])];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
