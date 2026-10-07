<?php

namespace Tests\Fixtures\Deferred;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Workbench\App\Models\User;

/**
 * Declares its own tools(), which builds the tool-search group from deferredActionTools() and loads actionTools(), as an
 * agent with tools of its own would.
 */
#[UseToolset('reports')]
#[DeferToolset]
final class OwnGroupAgent implements Agent, HasTools
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
        return 'You answer questions about the author\'s notes.';
    }

    /**
     * The group, then the loaded tools.
     */
    public function tools(): iterable
    {
        return [new ToolSearch($this->deferredActionTools()), ...$this->actionTools()];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
