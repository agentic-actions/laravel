<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\User;

/**
 * A Write whose ask() writes a row: it never gets a form.
 */
#[Expose(agents: ['asking'])]
final class AskingWrites extends Action
{
    protected string $description = 'Title a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
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
     * Write a row, then ask.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        $context->actor(User::class)->posts()->create(['title' => 'WRITTEN-BY-ASK', 'body' => 'x', 'status' => 'draft']);

        return $ask;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
