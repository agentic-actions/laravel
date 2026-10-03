<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A Write that needs an idempotency key, which MCP never carries.
 */
#[Expose]
final class McpKeyed extends Action
{
    protected string $description = 'Record a payment once.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Return the namespaced key.
     */
    public function handle(ActionContext $context): string
    {
        return $context->requireIdempotencyKey();
    }
}
