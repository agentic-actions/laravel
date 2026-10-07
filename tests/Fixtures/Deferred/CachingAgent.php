<?php

namespace Tests\Fixtures\Deferred;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * Caches its tool definitions, loads the approvals toolset, none of whose actions it receives since it keeps no
 * conversations, and finds the reports toolset through tool search.
 */
#[UseToolset('approvals')]
#[DeferToolset('reports')]
#[CacheToolDefinitions]
final class CachingAgent implements Agent, HasTools
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
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
