<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * An account-level Read under a bare #[Expose]: MCP opens it, and with tenancy on it belongs to the base path.
 */
#[Expose]
final class McpAccount extends Action
{
    protected string $description = 'Show the signed-in person\'s account settings.';

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
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
