<?php

namespace Tests\Fixtures\Discovery\Groups\Core;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * The core group's action, open to the web, the default toolset and MCP.
 */
#[Expose(web: true, agents: ['default'], mcp: true)]
final class RenameTeam extends Action
{
    protected string $description = 'Rename the team.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The new name.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(80)->required(),
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
     * Echo the name back.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        return $input->string('name')->toString();
    }
}
