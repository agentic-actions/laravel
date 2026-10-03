<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * Names MCP on purpose: a Read with a description, so MCP opens it, and the MCP guard row speaks up for it.
 */
#[Expose(mcp: true)]
final class McpNamed extends Action
{
    protected string $description = 'Summarise the signed-in author\'s week.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
