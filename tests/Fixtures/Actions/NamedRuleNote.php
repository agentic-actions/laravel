<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use AgenticActions\NamedRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A closure rule that reaches a model under its own name.
 */
final class NamedRuleNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The person's name.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
        ];
    }

    /**
     * A name is two words.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'name' => [new NamedRule('person_name', fn (mixed $value): bool => is_string($value) && str_word_count($value) >= 2)],
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
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
