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
 * The default toolset, for the author it was built for.
 */
#[UseToolset]
final class NotesAgent implements Agent, HasTools
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
        return 'You help the signed-in author manage their notes.';
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
