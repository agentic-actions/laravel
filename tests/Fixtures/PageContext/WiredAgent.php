<?php

namespace Tests\Fixtures\PageContext;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * The page context on an agent with the trait and HasMiddleware: the shape the "Page context" row passes.
 */
#[UseToolset]
#[WithPageContext]
final class WiredAgent implements Agent, HasMiddleware, HasTools
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
     * No step middleware: the row reads the class's shape, never what middleware() returns.
     *
     * @return array<int, mixed>
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
