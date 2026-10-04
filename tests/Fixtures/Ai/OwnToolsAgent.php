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
 * Declares its own tools() returning [], as an agent written from a blank stub does: it replaces the tools() that
 * InteractsWithActions gives it, so the default toolset never reaches the model.
 */
#[UseToolset]
final class OwnToolsAgent implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    /**
     * Build the agent for one author, or for a guest.
     */
    public function __construct(public ?User $user = null) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author.';
    }

    /**
     * No tools: the action tools are left out.
     */
    public function tools(): iterable
    {
        return [];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
