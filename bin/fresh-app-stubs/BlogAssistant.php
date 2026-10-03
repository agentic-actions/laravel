<?php

namespace App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[UseToolset]
final class BlogAssistant implements Agent, HasTools
{
    use InteractsWithActions;
    use Promptable;

    /**
     * Build the assistant for one author.
     */
    public function __construct(public User $user) {}

    /**
     * What the assistant does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author manage their blog posts.';
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
