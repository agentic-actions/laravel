<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write offered to the approvals toolset that asks the person for what a model's call left out: it waits on the
 * person as a confirmed action does, so the Approvals row names it, while a card's Summary row never does.
 */
#[Expose(agents: ['approvals'])]
final class AskDraft extends Action
{
    protected string $description = 'Draft a post for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The post's title and body.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->max(120)->required(),
            'body' => $schema->string()->title('Body')->max(5000)->required(),
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
     * The form's sentence and the body as several lines.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask->message('A few details for your post.')->textarea('body');
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
