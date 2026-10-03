<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Throwable;

/**
 * An account-level Write over MCP. It records the input handle() receives, runs Inside's code, and throws what a test
 * hands it. Its status must be one of two values it never says.
 */
#[Expose]
final class HostileWrite extends Action
{
    /**
     * The validated input of the last run.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    /**
     * What handle() throws, or null.
     */
    public static ?Throwable $throw = null;

    protected string $description = 'Save a note for the signed-in person.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * A title, a status and a nested note.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'status' => $schema->string()->nullable(),
            'meta' => $schema->object(['note' => $schema->string()])->nullable(),
        ];
    }

    /**
     * The status's allowed values, which a model is never told.
     *
     * @return array<string, list<string>>
     */
    public function rules(ActionContext $context): array
    {
        return ['status' => ['in:alpha-secret-status,beta-secret-status']];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the input, run Inside's code, and throw when asked.
     */
    public function handle(ActionContext $context, ValidatedInput $input): string
    {
        self::$received = $input->all();

        Inside::call($context);

        if (self::$throw !== null) {
            throw self::$throw;
        }

        return 'saved';
    }
}
