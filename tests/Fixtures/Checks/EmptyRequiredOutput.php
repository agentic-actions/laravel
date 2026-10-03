<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Declares output keys and marks none of them required.
 */
final class EmptyRequiredOutput extends Action
{
    protected ?Effect $effect = Effect::Read;

    /**
     * Keys a caller cannot rely on.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total' => $schema->integer(),
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
     * Never reached by these tests.
     *
     * @return array{total: int}
     */
    public function handle(ActionContext $context): array
    {
        return ['total' => 0];
    }
}
