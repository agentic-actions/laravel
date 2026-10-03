<?php

namespace Tests\Fixtures\Elicitation;

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
 * An agent that can pause for a person, with the asking toolset.
 */
#[UseToolset('asking')]
final class AskingAgent implements Agent, Conversational, HasTools
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
