<?php

namespace Tests\Fixtures\Mcp\Extra;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A bare #[Expose] Read without a description: MCP skips it quietly. Discovered only by the tests that name it, with
 * laravel/ai missing, since agents count a missing description as a mistake.
 */
#[Expose]
final class McpNoDescription extends Action
{
    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing to read.
     */
    public function handle(ActionContext $context): string
    {
        return 'none';
    }
}
