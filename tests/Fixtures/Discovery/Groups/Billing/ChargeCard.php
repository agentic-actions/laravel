<?php

namespace Tests\Fixtures\Discovery\Groups\Billing;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An optional module's action, open to the web, the billing toolset and MCP, so a test can see every surface it
 * reaches while the module is installed, and that none is left once it is not.
 */
#[Expose(web: true, agents: ['billing'], mcp: true)]
final class ChargeCard extends Action
{
    protected string $description = 'Charge the saved card.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Whether the caller's context offers the action at all; a test flips it.
     */
    public static bool $registers = true;

    /**
     * The amount.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cents' => $schema->integer()->min(1)->required(),
        ];
    }

    /**
     * Only the billing contact.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor?->getAttribute('email') === 'billing@example.test';
    }

    /**
     * Offered only while the test lets it be.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        return self::$registers;
    }

    /**
     * Echo the amount back.
     */
    public function handle(ActionContext $context, ValidatedInput $input): int
    {
        return $input->integer('cents');
    }
}
