<?php

namespace Modules\Demo\Agentic;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A module action whose namespace does not follow its path: the module maps Modules\Demo\ to Modules/Demo/app/.
 */
final class SavePhone extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The phone number.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'phone' => $schema->string()->required(),
        ];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Echo the number back.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        return $input->string('phone')->toString();
    }
}
