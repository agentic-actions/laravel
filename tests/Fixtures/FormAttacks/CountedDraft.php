<?php

namespace Tests\Fixtures\FormAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * An asking Write that counts how often its form is built, so a test sees what one request costs.
 */
#[Expose(agents: ['asking'])]
final class CountedDraft extends Action
{
    /**
     * How many times ask() ran.
     */
    public static int $asked = 0;

    /**
     * The input handle() ran with, if it ran.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $handled = null;

    /**
     * Forget what ran.
     */
    public static function reset(): void
    {
        self::$asked = 0;
        self::$handled = null;
    }

    protected string $description = 'Draft a note for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    protected bool $askForMissing = true;

    /**
     * A title and a short body.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->title('Title')->max(120)->required(),
            'body' => $schema->string()->title('Body')->max(50)->required(),
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
     * The form's sentence, counted.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        self::$asked++;

        return $ask->message('A few details for your note.');
    }

    /**
     * Record the input.
     */
    public function handle(ActionContext $context, ValidatedInput $input): mixed
    {
        self::$handled = $input->all();

        return null;
    }
}
