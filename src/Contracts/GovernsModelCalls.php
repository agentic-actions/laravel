<?php

namespace AgenticActions\Contracts;

use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\ModelCallRule;

/**
 * The app's say over what a model may do alone, per action and per context (for example an organization's settings).
 * Bind an implementation in the container; the package asks it for every model-driven call (agent, MCP, a queued run a
 * model started) at admission, when it lists an agent's tools and when it builds a card. Null keeps the package's rule:
 * Read and Write run, Destructive and External wait for a confirmation on the agent door and are never served over MCP.
 *
 * It is asked often, inside the tenant scope and with database writes refused: read only, and cache what you read for
 * the request. A throwable is reported and the call is refused.
 *
 * @api
 */
interface GovernsModelCalls
{
    /**
     * The rule for one model-driven call of this action in this context ($context->surface tells the door: Agent, Mcp
     * or Queue), or null for the package's own.
     */
    public function rule(string $action, ?Effect $effect, ActionContext $context): ?ModelCallRule;
}
