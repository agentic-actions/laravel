<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Declares a multi-type union that no rule in rules() owns.
 */
final class UnsupportedUnion extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * A key that is a string or an integer.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->union(['string', 'integer'])->required(),
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
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
