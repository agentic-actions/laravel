<?php

namespace Tests\Fixtures\Mcp\Extra;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * An MCP Read that counts how often its schema is built, and whose authorize() a test can close. Discovered only by
 * the tests that name it.
 */
#[Expose]
final class McpCounted extends Action
{
    /**
     * How many times schema() ran.
     */
    public static int $schemas = 0;

    /**
     * Whether authorize() lets the person in.
     */
    public static bool $open = true;

    protected string $description = 'Count the signed-in person\'s visits.';

    protected ?Effect $effect = Effect::Read;

    protected bool $idempotent = true;

    protected bool $tenantScoped = false;

    /**
     * One optional key, counted each time it is read.
     */
    public function schema(JsonSchema $schema): array
    {
        self::$schemas++;

        return [
            'label' => $schema->string(),
        ];
    }

    /**
     * Any signed-in person, while the test leaves it open.
     */
    public function authorize(ActionContext $context): bool
    {
        return self::$open && $context->actor !== null;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): bool
    {
        return true;
    }
}
