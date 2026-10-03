<?php

namespace Tests\Fixtures\Mcp\Extra;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * MCP-exposed, yet its input holds a key a model may never be offered. Discovered only by the tests that name it.
 */
#[Expose]
final class McpSecret extends Action
{
    protected string $description = 'Change the signed-in person\'s password.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * A key that matches agents.forbidden_keys.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'password' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Pretend to change it.
     */
    public function handle(ActionContext $context, ValidatedInput $input): bool
    {
        return true;
    }
}
