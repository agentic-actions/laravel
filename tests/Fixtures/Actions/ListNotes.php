<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Reads, projection and the Read guard.
 */
#[Expose]
final class ListNotes extends Action
{
    protected string $description = 'List the signed-in author\'s notes.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The notes, id and title only.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'posts' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'title' => $schema->string()->required(),
            ]))->required(),
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
     * The author's notes.
     *
     * @return array{posts: Collection<int, Post>}
     */
    public function handle(ActionContext $context): array
    {
        return ['posts' => $context->actor(User::class)->posts()->orderBy('id')->get()];
    }
}
