<?php

namespace Tests\Fixtures\Deferred;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * Declares its own tools(), which returns the loaded tools only, so the actions of its #[DeferToolset] toolset never
 * reach the model.
 */
#[UseToolset('reports')]
#[DeferToolset]
final class DroppedGroupAgent implements Agent, HasTools
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
     * The loaded tools, without the group.
     */
    public function tools(): iterable
    {
        return $this->actionTools();
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
