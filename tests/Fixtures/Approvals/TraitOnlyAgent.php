<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * An agent with laravel/ai's RemembersConversations trait but not the Conversational contract: it does not qualify.
 */
#[UseToolset('approvals')]
final class TraitOnlyAgent implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author.
     */
    public function __construct(public User $user) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author with their posts.';
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
