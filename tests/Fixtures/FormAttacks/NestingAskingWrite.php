<?php

namespace Tests\Fixtures\FormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Tests\Fixtures\Security\Inside;

/**
 * An asking Write whose handle() runs whatever Inside::$run holds with the context it was handed.
 */
#[Expose(agents: ['asking'])]
final class NestingAskingWrite extends Action
{
    /**
     * How many times handle() ran.
     */
    public static int $handled = 0;

    protected string $description = 'Tidy the signed-in author\'s posts, with a note.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * One note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->title('Note')->max(200)->required(),
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
     * Run what the test put inside.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        self::$handled++;
        Inside::call($context);

        return 'tidied';
    }
}
