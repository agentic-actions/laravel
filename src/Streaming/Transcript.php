<?php

namespace AgenticActions\Streaming;

use AgenticActions\Ai\ConfirmingAgents;
use AgenticActions\Approvals\ApprovalClaims;
use AgenticActions\Views\RefreshView;
use AgenticActions\Views\Rows;
use AgenticActions\Views\ShowsTable;
use AgenticActions\Views\Table;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Storage\StoredMessage;
use stdClass;
use Throwable;

/**
 * What a reloaded chat may show: the words of a conversation the server chose for this participant, the tables its
 * turns showed, and the cards and forms of the calls its newest turn still waits on.
 *
 * @api
 */
final class Transcript
{
    /**
     * The most tables a reload shows: the newest, so a long page stays cheap to build.
     */
    private const VIEWS = 20;

    /**
     * useChat's initial messages, oldest first: one per stored user or assistant message with text, words only. A
     * failed reply is left out; the words that started it stay. A turn is stored once the model has finished its
     * first step, so a turn that failed before that shows nowhere, words included. Empty when the store
     * says the conversation is not $as's, or cannot paginate. A store that cannot verify ownership leaves that check
     * to the caller.
     *
     * With the agent, continued for $as in this conversation, the newest turn comes back, when it is still waiting on
     * the person, as its message with, for each call the agent's own tools would still let $as answer, an input-less
     * tool part awaiting approval and a card or a form rebuilt now on the server. A call the pipeline would refuse or
     * run at once now, whose card no longer reads as the one first shown, or whose claim lapsed or was used, gets
     * neither; the next message the person sends settles it as not approved.
     *
     * With the agent, each assistant message also carries, before its words, the data-view parts of the tables it
     * showed $as, while the agent's own tools still offer their actions in the same tenant.
     *
     * @return list<array{id: string, role: 'user'|'assistant', parts: list<array<string, mixed>>}>
     */
    public static function forUseChat(string $conversationId, object $as, int $limit = 40, ?Agent $agent = null): array
    {
        $store = app(ConversationStore::class);

        if (! $store instanceof PaginatesConversations || ($store instanceof VerifiesConversationOwnership
            && ! $store->conversationBelongsTo($conversationId, Conversation::participantType($as), Conversation::participantKey($as)))) {
            return [];
        }

        // The first page, ordered by id, newest first; reversed so the oldest comes first. The empty cursor names no
        // position, so a ?cursor= the page's own request carries for another list never moves or breaks it.
        $stored = $store->paginateConversationMessages($conversationId, $limit, 'cursor', '')->items();
        $newest = $stored[0] ?? null;
        $views = $agent === null ? [] : self::views($conversationId, $as, $stored, $agent);
        $messages = [];

        foreach (array_reverse($stored) as $message) {
            if (! $message instanceof StoredMessage || ! in_array($message->role, ['user', 'assistant'], true)
                || $message->status === MessageStatus::Failed) {
                continue;
            }

            $parts = [...$views[$message->id] ?? [], ...(trim($message->content) === '' ? [] : [['type' => 'text', 'text' => $message->content]])];

            if ($message === $newest) {
                $parts = [...$parts, ...self::waiting($store, $conversationId, $message, $agent)];
            }

            if ($parts !== []) {
                $messages[] = ['id' => $message->id, 'role' => $message->role, 'parts' => $parts];
            }
        }

        return $messages;
    }

    /**
     * The data-view parts of the tables the page's assistant messages showed, by message, in the order they were shown,
     * the newest twenty at most: each only while the agent, continued for $as in this conversation, still offers its
     * action, which still shows a table, and only for $as and the tools' tenant, its rows re-projected onto the
     * action's columns now. Its ref only while RefreshView::allowed() holds. A failure, a missing table included, is
     * reported, the latter at most once an hour, and shows none.
     *
     * @param  array<array-key, mixed>  $stored
     * @return array<string, list<array<string, mixed>>>
     */
    private static function views(string $conversationId, object $as, array $stored, Agent $agent): array
    {
        $calls = [];

        foreach ($stored as $message) {
            foreach ($message instanceof StoredMessage && $message->role === 'assistant' ? $message->toolCalls() : [] as $call) {
                if (is_string($call['id'] ?? null)) {
                    $calls[$call['id']] = $message->id;
                }
            }
        }

        try {
            $tools = $calls === [] || ConfirmingAgents::conversation($agent) !== $conversationId ? [] : ConfirmingAgents::tools($agent);
            $context = $tools === [] ? null : reset($tools)->context();

            // The agent must act for $as in this very conversation, as for a waiting card.
            if ($context === null || ! $context->actor instanceof Model || $context->actor->getMorphClass() !== Conversation::participantType($as)
                || (string) $context->actor->getKey() !== (string) Conversation::participantKey($as)) {
                return [];
            }

            $views = [];

            $snapshots = AgenticView::query()
                ->where('conversation_id', $conversationId)
                ->whereIn('tool_call_id', array_keys($calls))
                ->where([
                    'participant_type' => Conversation::participantType($as),
                    'participant_id' => Conversation::participantKey($as),
                    'tenant_type' => $context->tenant?->getMorphClass(),
                    'tenant_id' => $context->tenant?->getKey(),
                ])
                ->orderByDesc('id')
                ->limit(self::VIEWS)
                ->get()
                ->reverse();

            foreach ($snapshots as $view) {
                $entry = ($tools[$view->action] ?? null)?->entry();
                $action = $entry?->shows() ? $entry->action() : null;

                if ($entry !== null && $action instanceof ShowsTable) {
                    $output = Table::output($entry, $action, Rows::of($view->table));
                    $ref = RefreshView::allowed($entry, $view->fixed) ? $view->id : null;
                    $views[$calls[$view->tool_call_id]][] = Table::part($entry, $output, $context, $view->tool_call_id, ($view->created_at ?? now())->format(DATE_ATOM), $ref);
                }
            }

            return $views;
        } catch (Throwable $exception) {
            AgenticView::reportFailure($exception);

            return [];
        }
    }

    /**
     * The newest message's waiting parts: per call the agent's own action tools can still answer, the input-less tool
     * part and the card or form rebuilt now. None unless the message is the assistant's paused turn and the agent,
     * continued in this conversation, can pause.
     *
     * @upstream The package rebuilds each waiting card and form on the server and never sends a call's stored arguments.
     *
     * @return list<array<string, mixed>>
     */
    private static function waiting(ConversationStore $store, string $conversationId, StoredMessage $message, ?Agent $agent): array
    {
        if ($agent === null || $message->role !== 'assistant' || $message->status !== MessageStatus::Paused
            || ! $store instanceof ResolvesPendingApprovals || ! ConfirmingAgents::supports($agent)
            || ConfirmingAgents::conversation($agent) !== $conversationId) {
            return [];
        }

        $tools = ConfirmingAgents::tools($agent);
        $parts = [];

        foreach ($store->pendingApprovalsFor($conversationId) as $pending) {
            // A card whose record changed since, a call that no longer needs a form, or a claim that lapsed or was spent
            // would be refused: none is shown.
            if (($paused = ($tools[$pending->tool] ?? null)?->card($pending->id, $pending->arguments)) === null
                || ! app(ApprovalClaims::class)->holds($conversationId, $pending->id, $paused)) {
                continue;
            }

            // useChat requires the input key; the stored arguments never fill it.
            $parts[] = ['type' => 'tool-'.$pending->tool, 'toolCallId' => $pending->id, 'state' => 'approval-requested', 'input' => new stdClass, 'approval' => ['id' => $pending->id]];
            $parts[] = $paused->part($pending->id);
        }

        return $parts;
    }
}
