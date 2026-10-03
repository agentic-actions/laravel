<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\Rule;
use Workbench\App\Models\Post;

/**
 * Checks the id in authorize(), but its exists rule answers for every row in every tenant.
 */
#[Expose]
final class IdUnscopedExists extends Action
{
    protected string $description = 'Mark a note as starred.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note to star.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }

    /**
     * An unscoped exists rule.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'post_id' => [Rule::exists('posts', 'id')],
        ];
    }

    /**
     * The note belongs to the actor.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        return Post::query()->whereKey($input->integer('post_id'))->where('user_id', $context->actor?->getAuthIdentifier())->exists();
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
