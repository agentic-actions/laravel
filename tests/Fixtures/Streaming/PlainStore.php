<?php

namespace Tests\Fixtures\Streaming;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Throwable;

/**
 * A conversation store that stores and nothing more: it neither paginates nor verifies ownership. Not final:
 * PagingStore adds pagination.
 */
class PlainStore implements ConversationStore
{
    /**
     * Wrap the database store.
     */
    public function __construct(protected DatabaseConversationStore $store = new DatabaseConversationStore) {}

    /**
     * {@inheritdoc}
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return $this->store->latestConversationId($participantType, $participantId, $agent);
    }

    /**
     * {@inheritdoc}
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $this->store->storeConversation($participantType, $participantId, $title, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        return $this->store->storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
    }

    /**
     * {@inheritdoc}
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        return $this->store->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
    }

    /**
     * {@inheritdoc}
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->store->getLatestConversationMessages($conversationId, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        $this->store->storeApprovalResults($conversationId, $toolResults);
    }
}
