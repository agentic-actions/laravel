<?php

namespace Tests\Fixtures\Elicitation;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A Write whose own rules() refuse a date in the past, beyond what its schema says.
 */
#[Expose(agents: ['asking'])]
final class AskingRules extends Action
{
    /**
     * The validated input the last handle() ran with.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    protected string $description = 'Publish a post on a later day.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * The day.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'when' => $schema->string()->format('date')->required(),
        ];
    }

    /**
     * Only a later day.
     */
    public function rules(ActionContext $context): array
    {
        return ['when' => 'after:today'];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
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
