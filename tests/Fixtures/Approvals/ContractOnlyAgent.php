<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * An agent that implements laravel/ai's RemembersConversations contract by hand, without the trait: it does not
 * qualify.
 */
#[UseToolset('approvals')]
final class ContractOnlyAgent implements Agent, HasTools, RemembersConversations
{
    use InteractsWithActions;
    use Promptable;

    /**
     * The conversation, when one is chosen.
     */
    private ?string $conversationId = null;

    /**
     * The participant, when one is chosen.
     */
    private ?object $participant = null;

    /**
     * Build the agent for one author.
     */
    public function __construct(public User $user) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You help the signed-in author with their posts.';
    }

    /**
     * No stored history.
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Start a new conversation for the participant.
     */
    public function forParticipant(object $participant): static
    {
        $this->conversationId = null;
        $this->participant = $participant;

        return $this;
    }

    /**
     * Start a new conversation for the user.
     */
    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    /**
     * Continue a conversation.
     */
    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;
        $this->participant = $as;

        return $this;
    }

    /**
     * Continue a conversation, or start one.
     */
    public function continueOrStart(?string $conversationId, object $as): static
    {
        return $conversationId === null ? $this->forParticipant($as) : $this->continue($conversationId, $as);
    }

    /**
     * Start a new conversation: this agent keeps none.
     */
    public function continueLastConversation(object $as): static
    {
        return $this->forParticipant($as);
    }

    /**
     * The chosen conversation.
     */
    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Whether a participant is chosen.
     */
    public function hasConversationParticipant(): bool
    {
        return $this->participant !== null;
    }

    /**
     * The chosen participant.
     */
    public function conversationParticipant(): ?object
    {
        return $this->participant;
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
