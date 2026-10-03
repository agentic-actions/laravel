<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A list its rules() cap at three distinct tags, written as rules rather than a schema: an oversized list is refused
 * once it is cut to one item past the cap.
 */
#[Expose]
final class TagNotes extends Action
{
    protected string $description = 'Find notes by up to three tags.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Up to three tags, each once.
     */
    public function rules(ActionContext $context): array
    {
        return ['tags' => ['required', 'array', 'max:3'], 'tags.*' => ['string', 'distinct']];
    }

    /**
     * The tags asked for, as they came.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['tags' => $schema->array()->items($schema->string())->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The tags asked for.
     *
     * @return array{tags: mixed}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        return ['tags' => $input->input('tags')];
    }
}
