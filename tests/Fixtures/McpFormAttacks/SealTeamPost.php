<?php

namespace Tests\Fixtures\McpFormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A tenant-scoped asking Write behind a bare #[Expose] whose second field is a secret that agents.forbidden_keys does
 * not match: agents are offered it, and a form must never ask for it.
 */
#[Expose]
final class SealTeamPost extends Action
{
    /**
     * Whether handle() ran.
     */
    public static bool $handled = false;

    protected string $description = 'Seal a post in the current team behind a phrase.';

    protected ?Effect $effect = Effect::Write;

    protected bool $askForMissing = true;

    /**
     * The post and the phrase.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->required(),
            'pass_phrase' => $schema->string()->title('Phrase')->required(),
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
