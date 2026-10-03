<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * An account-level Read over MCP whose handle() runs Inside's code and returns a note a test chooses, such as one
 * that pretends to end the data and give instructions.
 */
#[Expose]
final class HostileRead extends Action
{
    /**
     * The note handle() returns.
     */
    public static string $note = 'plain';

    protected string $description = 'Read a note for the signed-in person.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * The note.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['note' => $schema->string()->required()];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Run Inside's code, then return the note.
     *
     * @return array{note: string}
     */
    public function handle(ActionContext $context): array
    {
        Inside::call($context);

        return ['note' => self::$note];
    }
}
