<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write with $askForMissing that agents are never offered: only a model's call asks, so the Approvals row leaves it
 * out.
 */
#[Expose(web: true)]
final class AskWebOnly extends Action
{
    protected string $description = 'Title a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

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
     * Never reached by these tests.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
