<?php

namespace AgenticActions\Ai;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Throwable;

/**
 * Which agents may pause for a person (offered Destructive and External actions, and asking for missing fields): those
 * whose history comes from laravel/ai's store.
 *
 * @internal
 */
final class ConfirmingAgents
{
    /**
     * Whether this agent, as it stands, can pause for a person: its class qualifies, it has a conversation
     * participant now, and the bound store reads pending calls and ownership.
     *
     * @upstream The package offers Destructive and External tools only to agents whose history comes from the conversation store.
     */
    public static function supports(Agent $agent): bool
    {
        return self::classSupports($agent::class)
            && method_exists($agent, 'hasConversationParticipant') && $agent->hasConversationParticipant() === true
            && self::storeSupports();
    }

    /**
     * The class half, for actions:check: Conversational, plus laravel/ai's RemembersConversations trait. The contract
     * alone does not qualify.
     *
     * @upstream The package offers Destructive and External tools only to agents that use the RemembersConversations trait.
     */
    public static function classSupports(string $class): bool
    {
        return is_subclass_of($class, Conversational::class)
            && in_array(RemembersConversations::class, class_uses_recursive($class), true);
    }

    /**
     * Whether the bound conversation store reads pending calls and conversation ownership; false when none resolves.
     */
    public static function storeSupports(): bool
    {
        if (! interface_exists(ResolvesPendingApprovals::class) || ! app()->bound(ConversationStore::class)) {
            return false;
        }

        try {
            $store = app(ConversationStore::class);
        } catch (Throwable) {
            return false;
        }

        return $store instanceof ResolvesPendingApprovals && $store instanceof VerifiesConversationOwnership;
    }

    /**
     * The agent's own action tools, as it builds them now, by name, at the top level of its tools and inside a
     * tool-search group; none when it declares no tools.
     *
     * @upstream An action tool inside a tool-search group is found as one at the top level is.
     *
     * @return array<string, ActionTool>
     */
    public static function tools(Agent $agent): array
    {
        $tools = [];

        foreach ($agent instanceof HasTools ? $agent->tools() : [] as $tool) {
            foreach ($tool instanceof ToolSearch ? $tool->tools : [$tool] as $inner) {
                if ($inner instanceof ActionTool) {
                    $tools[$inner->name()] = $inner;
                }
            }
        }

        return $tools;
    }

    /**
     * The agent's current conversation id, or null when it has none yet or does not remember conversations: the one
     * place the package reads it, so no caller needs a method the Agent contract does not declare.
     */
    public static function conversation(?Agent $agent): ?string
    {
        if ($agent === null || ! method_exists($agent, 'currentConversation')) {
            return null;
        }

        $conversation = $agent->currentConversation();

        return is_string($conversation) ? $conversation : null;
    }
}
