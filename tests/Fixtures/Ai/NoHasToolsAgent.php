<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * Uses InteractsWithActions without implementing HasTools, so laravel/ai never asks it for its tools.
 */
#[UseToolset]
final class NoHasToolsAgent implements Agent
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
