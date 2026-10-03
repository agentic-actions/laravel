<?php

namespace Tests\Fixtures\McpFormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A tenant-scoped Destructive action that declares $askForMissing behind a bare #[Expose]: MCP must neither list it,
 * ask for it, nor run it, even from inside an MCP form's run.
 */
#[Expose]
final class PurgeTeamNotes extends Action
{
    /**
     * Whether handle() ran.
     */
    public static bool $handled = false;

    protected string $description = 'Delete the current team\'s posts written before a date.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $askForMissing = true;

    /**
     * The cut-off date.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'before' => $schema->string()->title('Before')->format('date')->required(),
        ];
    }

    /**
     * Any signed-in member; membership already ran.
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
        self::$handled = true;

        return null;
    }
}
