<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Needs an idempotency key: 428 without one, a namespaced key with one.
 */
#[Expose(web: true)]
final class KeyedNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The namespaced key.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required(),
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
     * Return the namespaced key.
     *
     * @return array{key: string}
     */
    public function handle(ActionContext $context): array
    {
        return ['key' => $context->requireIdempotencyKey()];
    }
}
