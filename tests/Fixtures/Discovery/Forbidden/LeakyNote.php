<?php

namespace Tests\Fixtures\Discovery\Forbidden;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Agent-exposed, yet its input holds a key agents may never be offered.
 */
#[Expose]
final class LeakyNote extends Action
{
    protected string $description = 'Save a note with a service key.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note and a key that matches agents.forbidden_keys.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->required(),
            'api_key' => $schema->string()->required(),
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
     * Keep the note.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        return $input->string('note')->toString();
    }
}
