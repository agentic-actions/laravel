<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Approvals\ApprovalTicket;
use AgenticActions\Discovery\Catalog;
use AgenticActions\Exposure\Entry;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use LogicException;

/**
 * Builds the action tools an agent receives for one context: the agent catalog, one ActionTool per entry.
 *
 * @internal
 */
final class ToolFactory
{
    /**
     * The action tools for these toolsets, built now, for this context. Destructive and External actions are offered
     * only when the agent can pause for a person (ConfirmingAgents); without an agent they are never offered. An asking
     * action's tool gets the agent too, so it can pause for the person's answer; without one its call is refused as
     * before. So does the tool of an action that shows a table, so the table is kept with the agent's conversation.
     *
     * @param  list<string>  $toolsets
     * @return list<ActionTool>
     *
     * @throws LogicException when laravel/ai is not installed
     */
    public function make(ActionContext $context, array $toolsets, ?Agent $agent = null): array
    {
        if (! interface_exists(Tool::class)) {
            throw new LogicException('laravel/ai is not installed: composer require laravel/ai.');
        }

        // The catalog's ticket names no call: it lets admission list the gated actions for an agent that can pause.
        $pausing = $agent !== null && ConfirmingAgents::supports($agent) ? $agent : null;
        $listing = $pausing !== null ? $context->withApproval(new ApprovalTicket(null, null)) : $context;

        return array_map(
            fn (Entry $entry): ActionTool => new ActionTool($entry, $context, $toolsets, $entry->effect?->isModelSafe() === false || $entry->asks || $entry->shows() ? $pausing : null),
            app(Catalog::class)->forAgents($listing, $toolsets),
        );
    }
}
