<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * The streaming agents' shared shape, as the chat recipe builds one: the stream toolset's actions beside the
 * hand-written tools, remembered conversations, and middleware() returning actionMiddleware(). Abstract, so the
 * scanner skips it; each subclass carries its own attributes.
 */
abstract class StreamingAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author; $wired false leaves actionMiddleware() out of middleware().
     */
    public function __construct(
        public User $user,
        public ?Model $tenant = null,
        public ?string $locale = null,
        public bool $wired = true,
    ) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author with their notes.';
    }

    /**
     * The action tools, then the hand-written ones.
     *
     * @return list<mixed>
     */
    public function tools(): iterable
    {
        return [...$this->actionTools(), new TracedTool, new StatefulTool, new SilentTool, new ThrowingTool];
    }

    /**
     * The step middleware the agent's attributes ask for.
     *
     * @return list<mixed>
     */
    public function middleware(): array
    {
        return $this->wired ? [...$this->actionMiddleware()] : [];
    }

    /**
     * The author, tenant and locale the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->tenant, $this->locale);
    }
}
