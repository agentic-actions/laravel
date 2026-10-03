<?php

namespace Workbench\App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Ai\Tools\CountDrafts;
use Workbench\App\Models\User;

/**
 * The copilot of docs/copilot.md, for one author: the default toolset, which receives only the actions that are not
 * tenant-scoped since the author has no team, beside a hand-written tool; remembered conversations; and the page the
 * author has open.
 */
#[UseToolset]
#[WithPageContext]
final class BlogAssistant implements Agent, Conversational, HasMiddleware, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

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
     * The action tools, then the hand-written one.
     *
     * @return list<mixed>
     */
    public function tools(): iterable
    {
        return [...$this->actionTools(), new CountDrafts($this->user)];
    }

    /**
     * The step middleware the attributes ask for: the page context.
     *
     * @return list<mixed>
     */
    public function middleware(): array
    {
        return [...$this->actionMiddleware()];
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
