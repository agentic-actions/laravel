<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write whose required field is a password: a form never asks for it. Its own toolset keeps it out of the asking
 * agents' catalog, which leaves out any tool agents.forbidden_keys matches.
 */
#[Expose(agents: ['secrets'])]
final class AskingSecret extends Action
{
    protected string $description = 'Protect a post with a password.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The password.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'password' => $schema->string()->required(),
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
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
