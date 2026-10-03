<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * An agent that can pause for a person: Conversational, laravel/ai's RemembersConversations trait, and the approvals
 * toolset.
 */
#[UseToolset('approvals')]
final class ConfirmingAgent implements Agent, Conversational, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author.
     */
    public function __construct(
        public User $user,
        public ?Model $tenant = null,
        public ?string $locale = null,
    ) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author with their posts.';
    }

    /**
     * The author, tenant and locale the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->tenant, $this->locale);
    }
}
