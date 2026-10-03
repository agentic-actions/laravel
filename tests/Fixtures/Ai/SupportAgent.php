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
 * The support toolset, for a signed-in author or a signed-out visitor.
 */
#[UseToolset('support')]
final class SupportAgent implements Agent, HasTools
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
        return 'You answer support questions.';
    }

    /**
     * The person the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
