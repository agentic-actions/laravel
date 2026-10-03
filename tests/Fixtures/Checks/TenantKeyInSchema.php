<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Lets the caller name the team in its input, under a Team tenant model.
 */
#[Expose(web: true)]
final class TenantKeyInSchema extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The tenant model's foreign key, as ordinary input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->integer()->required(),
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
