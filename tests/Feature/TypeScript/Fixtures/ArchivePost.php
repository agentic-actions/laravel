<?php

namespace Tests\Feature\TypeScript\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Mounted on hand-written routes only: its "post" key comes from the URL, so TypeScript takes it as a parameter.
 */
final class ArchivePost extends Action
{
    protected string $description = 'Archive one of your posts.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The post from the URL, and why.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
            'reason' => $schema->string(),
        ];
    }

    /**
     * What the caller gets back.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'archived' => $schema->boolean()->required(),
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
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        return ['archived' => true];
    }
}
