<?php

namespace Tests\Feature\TypeScript\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Every schema shape the TypeScript emitter maps, with no output schema.
 */
final class EveryShape extends Action
{
    protected string $description = 'Every schema shape the emitter maps. It keeps */ inside the comment.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['shapes', 'it\'s'];

    /**
     * One property per row of the type mapping.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'published'])->required(),
            'priority' => $schema->integer()->enum([1, 2, 3])->default(1)->required(),
            'ratio' => $schema->number(),
            'flag' => $schema->boolean()->required(),
            'tags' => $schema->array()->items($schema->string())->required(),
            'labels' => $schema->array()->items($schema->string()->enum(['a', 'b'])),
            'anything' => $schema->array(),
            'meta' => $schema->object(),
            'author' => $schema->object([
                'name' => $schema->string()->required()->description('As printed on the byline.'),
                'email' => $schema->string()->nullable(),
            ])->required(),
            'lines' => $schema->array()->items($schema->object([
                'sku' => $schema->string()->required(),
                'qty' => $schema->integer(),
            ])),
            'attachment' => $schema->string()->format('binary'),
            'content-type' => $schema->string(),
            'note' => $schema->string()->nullable()->required(),
        ];
    }

    /**
     * Anyone.
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
