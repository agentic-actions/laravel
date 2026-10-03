<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write that keeps its input on the instance in prepareForValidation() and writes whatever the instance holds into
 * the form's sentence: only a fresh instance keeps the model's arguments out of the form.
 */
#[Expose(agents: ['asking'])]
final class AskingEcho extends Action
{
    /**
     * The input prepareForValidation() saw on this instance.
     *
     * @var array<string, mixed>|null
     */
    public ?array $seen = null;

    protected string $description = 'Leave a note on a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The note and a tag.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->min(1)->required(),
            'tag' => $schema->string(),
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
     * Keep the input on this instance.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        $this->seen = $input;

        return $input;
    }

    /**
     * Whatever this instance holds, as the sentence.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        return $ask->message('Seen: '.json_encode($this->seen));
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        return null;
    }
}
