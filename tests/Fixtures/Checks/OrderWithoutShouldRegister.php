<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Translates an agent's title into an id before an input-taking authorize(), with nothing in front of fromAgent().
 */
#[Expose]
final class OrderWithoutShouldRegister extends Action
{
    protected string $description = 'Archive a note by its title.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The canonical input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer()->required(),
        ];
    }

    /**
     * What an agent is offered.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * Turn the title into a note.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        return ['note' => 1];
    }

    /**
     * The note belongs to the actor.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
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
