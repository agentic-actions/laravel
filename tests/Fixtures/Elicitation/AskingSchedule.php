<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write with one field of each kind a form holds, and a pattern the form never sends. Sharing defaults to true in
 * prepareForValidation() when the input leaves it out.
 */
#[Expose(agents: ['asking'])]
final class AskingSchedule extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Schedule a post.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The schedule's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'when' => $schema->string()->format('date')->required(),
            'at' => $schema->string()->format('date-time'),
            'count' => $schema->integer()->min(1)->max(10),
            'share' => $schema->boolean(),
            'tags' => $schema->array()->items($schema->string()->enum(['news', 'launch', 'ops']))->min(1),
            'code' => $schema->string()->pattern('^[A-Z]{3}$'),
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
     * Share unless the input says otherwise.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        return $input + ['share' => true];
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();

        return null;
    }
}
