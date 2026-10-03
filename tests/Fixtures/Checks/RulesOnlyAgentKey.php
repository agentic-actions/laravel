<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Checks a key in rules() that schema() never declares, so the agent door always prunes it.
 */
#[Expose]
final class RulesOnlyAgentKey extends Action
{
    protected string $description = 'Create a note in a team.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
        ];
    }

    /**
     * A rule for a key nobody declared.
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
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
