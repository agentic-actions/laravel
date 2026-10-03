<?php

namespace Tests\Fixtures\FormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An asking Write whose agents speak their own vocabulary: fromAgent() turns the agent's "pin" into the canonical
 * "password", which agents are never offered.
 */
#[Expose(agents: ['asking'])]
final class AgentVocabulary extends Action
{
    /**
     * Whether handle() ran.
     */
    public static bool $handled = false;

    protected string $description = 'Lock one of the signed-in author\'s drafts.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The canonical input: a title and a password.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'password' => $schema->string()->required(),
        ];
    }

    /**
     * What agents are offered: the title, and a "pin" titled as a plain code.
     */
    public function agentSchema(JsonSchema $schema): ?array
    {
        return [
            'title' => $schema->string()->title('Title')->required(),
            'pin' => $schema->integer()->title('Code'),
        ];
    }

    /**
     * The agent's pin becomes the canonical password.
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        return ['title' => $input->string('title')->value(), ...($input->has('pin') ? ['password' => (string) $input->integer('pin')] : [])];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record that it ran.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = true;

        return null;
    }
}
