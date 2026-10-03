<?php

namespace Tests\Fixtures\Misconfigured;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Named for the web while overriding agentSchema(): the canonical input carries ids an agent never saw.
 */
#[Expose(web: true)]
final class WebWithAgentSchema extends Action
{
    protected string $description = 'Rename a note.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * What an agent is offered.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'current_title' => $schema->string()->required(),
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
