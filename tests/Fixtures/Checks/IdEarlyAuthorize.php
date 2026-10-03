<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Offers agents an id, and checks nothing about it: its authorize() runs before any input is read.
 */
#[Expose]
final class IdEarlyAuthorize extends Action
{
    protected string $description = 'Mark a note as read.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note to mark.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }

    /**
     * Any signed-in author, whatever the note.
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
