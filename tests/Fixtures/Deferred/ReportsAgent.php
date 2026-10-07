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
 * Loads the reports toolset on every step and finds the default toolset through tool search, for an author or a
 * signed-out visitor.
 */
#[UseToolset('reports')]
#[DeferToolset]
final class ReportsAgent implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    /**
     * Build the agent for an author, or for a visitor with no account.
     */
    public function __construct(public ?User $user = null) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You answer questions about the author\'s notes.';
    }

    /**
     * The person the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
