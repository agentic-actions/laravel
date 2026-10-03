<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Returns the input it validated, for actions:run's input tests. Listed under discovery.classes by those tests only.
 */
final class RunEcho extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Nested, list and nullable input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'count' => $schema->integer(),
            'draft' => $schema->boolean(),
            'excerpt' => $schema->string()->nullable(),
            'tags' => $schema->array()->items($schema->string()),
            'author' => $schema->object([
                'name' => $schema->string(),
                'age' => $schema->integer(),
            ]),
        ];
    }

    /**
     * The same keys back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'count' => $schema->integer(),
            'draft' => $schema->boolean(),
            'excerpt' => $schema->string()->nullable(),
            'tags' => $schema->array()->items($schema->string()),
            'author' => $schema->object([
                'name' => $schema->string(),
                'age' => $schema->integer(),
            ]),
        ];
    }

    /**
     * Anyone at the CLI.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Echo the validated input.
     *
     * @return array<string, mixed>
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        return $input->all();
    }
}
