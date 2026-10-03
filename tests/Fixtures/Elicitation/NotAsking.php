<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write without $askForMissing: its incomplete call is refused, as always.
 */
#[Expose(agents: ['asking'])]
final class NotAsking extends Action
{
    protected string $description = 'Title a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * The title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
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
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
