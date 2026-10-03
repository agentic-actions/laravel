<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Workbench\App\Models\User;

/**
 * Builds its rules from the actor, so the static rows, which pass a system() context, cannot read them.
 */
final class RulesNeedActor extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * The note to copy.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->integer()->required(),
        ];
    }

    /**
     * A rule scoped through the actor.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'note' => [Rule::exists('posts', 'id')->where('user_id', $context->actor(User::class)->getKey())],
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
