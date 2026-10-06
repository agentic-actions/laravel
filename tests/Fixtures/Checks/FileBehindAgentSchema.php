<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Offers agents a caption alone through agentSchema(), while schema() also takes a file: a model's call is validated
 * against schema() too, where the file is accepted on HTTP only.
 */
#[Expose]
final class FileBehindAgentSchema extends Action
{
    protected string $description = 'Caption a note.';

    protected ?Effect $effect = Effect::Write;

    /**
     * A caption, and a file a form may send.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'caption' => $schema->string()->required(),
            'attachment' => $schema->string()->format('binary'),
        ];
    }

    /**
     * What agents are offered: the caption alone.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'caption' => $schema->string()->required(),
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
