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
 * Checks the id in authorize(), and scopes its exists rule to the actor: it passes both id rows.
 */
#[Expose]
final class IdScopedExists extends Action
{
    protected string $description = 'Pin a note.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The note to pin.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required(),
        ];
    }

    /**
     * An exists rule scoped to the actor.
     */
    public function rules(ActionContext $context): array
    {
        return [
            'post_id' => [Rule::exists('posts', 'id')->where('user_id', $context->actor?->getAuthIdentifier())],
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
