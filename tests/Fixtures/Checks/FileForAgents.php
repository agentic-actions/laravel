<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Offers agents a file field, which only an HTTP form can send.
 */
#[Expose]
final class FileForAgents extends Action
{
    protected string $description = 'Attach a file to a note.';

    protected ?Effect $effect = Effect::Write;

    /**
     * A caption and the file.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'caption' => $schema->string()->required(),
            'attachment' => $schema->string()->format('binary')->required(),
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
