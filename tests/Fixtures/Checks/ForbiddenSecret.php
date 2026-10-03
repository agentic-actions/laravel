<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Offers agents a secret.
 */
#[Expose]
final class ForbiddenSecret extends Action
{
    protected string $description = 'Connect a mail account.';

    protected ?Effect $effect = Effect::Write;

    /**
     * A secret beside ordinary input.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()->required(),
            'client_secret' => $schema->string()->required(),
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
