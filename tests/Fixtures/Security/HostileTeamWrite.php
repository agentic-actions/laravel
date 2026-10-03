<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A tenant-scoped Write over MCP that records the input prepareForValidation() saw, the validated input, and the
 * tenant it ran in.
 */
#[Expose]
final class HostileTeamWrite extends Action
{
    /**
     * The validated input and the tenant's key of each run.
     *
     * @var list<array{input: array<string, mixed>, tenant: mixed}>
     */
    public static array $runs = [];

    /**
     * The input prepareForValidation() saw, each call.
     *
     * @var list<array<string, mixed>>
     */
    public static array $prepared = [];

    protected string $description = 'Save a note in the current team.';

    protected ?Effect $effect = Effect::Write;

    /**
     * A title and a nested note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'meta' => $schema->object(['note' => $schema->string()])->nullable(),
        ];
    }

    /**
     * Record the input as the action's own code first sees it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        self::$prepared[] = $input;

        return $input;
    }

    /**
     * Any signed-in person; membership already ran.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        self::$runs[] = ['input' => $input->all(), 'tenant' => $context->tenant()->getKey()];

        return 'saved';
    }
}
