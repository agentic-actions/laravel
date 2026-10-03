<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use RuntimeException;

/**
 * Crashes in handle().
 */
#[Expose]
final class McpCrashes extends Action
{
    protected string $description = 'Recount the signed-in person\'s posts.';

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
     * Crash.
     */
    public function handle(ActionContext $context): never
    {
        throw new RuntimeException('The recount crashed.');
    }
}
