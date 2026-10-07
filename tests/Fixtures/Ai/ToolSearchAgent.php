<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Workbench\App\Models\User;

/**
 * An agent that can pause for a person and keeps every action tool of its toolsets inside one tool-search group, so
 * the model finds each by searching: the approvals toolset's cards, the asking toolset's forms and the views toolset's
 * tables.
 */
#[UseToolset('approvals', 'asking', 'views')]
final class ToolSearchAgent implements Agent, Conversational, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author, in a tenant or none.
     */
    public function __construct(
        public User $user,
        public ?Model $tenant = null,
    ) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author with their posts.';
    }

    /**
     * The action tools, all inside one tool-search group.
     */
    public function tools(): iterable
    {
        return [new ToolSearch($this->actionTools())];
    }

    /**
     * The author and tenant the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->tenant);
    }
}
