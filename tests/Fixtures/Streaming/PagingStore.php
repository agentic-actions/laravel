<?php

namespace Tests\Fixtures\Streaming;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Laravel\Ai\Contracts\PaginatesConversations;

/**
 * A conversation store that paginates but cannot verify ownership, so ownership is the caller's check.
 */
final class PagingStore extends PlainStore implements PaginatesConversations
{
    /**
     * {@inheritdoc}
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        return $this->store->paginateConversationMessages($conversationId, $perPage, $cursorName, $cursor);
    }
}
