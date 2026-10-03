<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write whose innocently named field checks the person's password: a form never asks for it.
 */
#[Expose(agents: ['asking'])]
final class AskingPassword extends Action
{
    protected string $description = 'Archive every post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * What the person confirms with.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm_with' => $schema->string()->required(),
        ];
    }

    /**
     * The person's current password.
     */
    public function rules(ActionContext $context): array
    {
        return ['confirm_with' => 'current_password'];
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
