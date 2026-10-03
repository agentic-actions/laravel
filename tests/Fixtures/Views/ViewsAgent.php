<?php

namespace Tests\Fixtures\Views;

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
 * An agent answering questions about the author's posts with tables: the views and default toolsets, remembered
 * conversations, and input the host fixes.
 */
#[UseToolset('views', 'default')]
final class ViewsAgent implements Agent, Conversational, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author, in a tenant or none, with input the host fixes.
     *
     * @param  array<string, mixed>  $fixed
     */
    public function __construct(
        public User $user,
        public ?Model $tenant = null,
        public array $fixed = [],
    ) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You answer the signed-in author\'s questions about their posts.';
    }

    /**
     * The author, tenant and fixed input the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->tenant)->withFixed($this->fixed);
    }
}
