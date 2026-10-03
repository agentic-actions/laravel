<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The support toolset's lookup, which a signed-out visitor's support bot may run because of $guests.
 */
#[Expose(agents: ['support'])]
final class SupportLookup extends Action
{
    protected string $description = 'Look up the support hours.';

    protected ?Effect $effect = Effect::Read;

    protected bool $guests = true;

    /**
     * The hours.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->string()->required(),
        ];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * The hours.
     *
     * @return array{hours: string}
     */
    public function handle(ActionContext $context): array
    {
        return ['hours' => '9 to 5'];
    }
}
