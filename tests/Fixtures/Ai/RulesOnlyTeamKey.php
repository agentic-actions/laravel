<?php

namespace Tests\Fixtures\Ai;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * rules() names team_id, which schema() never advertises, so the agent door's prune keeps it from a model. It
 * records the input and the context handle() received.
 */
#[Expose]
final class RulesOnlyTeamKey extends Action
{
    /**
     * The input the last handle() received.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $received = null;

    /**
     * The context the last handle() received.
     */
    public static ?ActionContext $context = null;

    protected string $description = 'Save a titled entry with optional details.';

    protected ?Effect $effect = Effect::Write;

    /**
     * What an agent is offered: a title, an optional object and an optional list of objects.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'meta' => $schema->object([
                'tag' => $schema->string(),
            ]),
            'items' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
            ])),
        ];
    }

    /**
     * A key only the server knows about.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'team_id' => ['integer'],
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
     * Record what arrived.
     */
    public function handle(ActionContext $context, ValidatedInput $input): null
    {
        self::$received = $input->all();
        self::$context = $context;

        return null;
    }

    /**
     * Forget what the last call recorded.
     */
    public static function reset(): void
    {
        self::$received = null;
        self::$context = null;
    }
}
