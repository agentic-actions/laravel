<?php

namespace App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use App\Ai\Tools\SlowLookup;
use App\Models\User;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * The chat recipe's agent (docs/copilot.md) on the probe toolset: one slow action beside one slow hand-written tool.
 * It carries no #[WithPageContext], because the probe app has no Inertia.
 */
#[UseToolset('probe')]
final class ProbeAssistant implements Agent, Conversational, HasMiddleware, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the assistant for one user.
     */
    public function __construct(public User $user) {}

    /**
     * What the assistant does.
     */
    public function instructions(): string
    {
        return 'You save notes for the signed-in user.';
    }

    /**
     * The action tools, then the hand-written one.
     *
     * @return list<mixed>
     */
    public function tools(): iterable
    {
        return [...$this->actionTools(), new SlowLookup];
    }

    /**
     * The step middleware the attributes ask for (none here).
     *
     * @return list<mixed>
     */
    public function middleware(): array
    {
        return [...$this->actionMiddleware()];
    }

    /**
     * The user the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
