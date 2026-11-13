<?php

namespace Tests\Fixtures\Discovery\Groups\Billing;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An optional module's Destructive action: it waits for a person on the agent door and is never served over MCP.
 */
#[Expose(web: true, agents: ['billing'])]
final class RefundCharge extends Action
{
    protected string $description = 'Refund a charge.';

    protected ?Effect $effect = Effect::Destructive;

    /**
     * The charge.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'charge' => $schema->string()->max(40)->required(),
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
     * Echo the charge back.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        return $input->string('charge')->toString();
    }
}
