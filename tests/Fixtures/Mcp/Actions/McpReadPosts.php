<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Workbench\App\Models\Post;

/**
 * An account-level Read: the signed-in person's posts.
 */
#[Expose]
final class McpReadPosts extends Action
{
    protected string $description = 'List the signed-in person\'s posts.';

    protected ?Effect $effect = Effect::Read;

    protected bool $idempotent = true;

    protected bool $tenantScoped = false;

    /**
     * The posts, id and title only.
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
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The person's posts.
     *
     * @return array{posts: Collection<int, Post>}
     */
    public function handle(ActionContext $context): array
    {
        return ['posts' => Post::query()->where('user_id', $context->actor()->getAuthIdentifier())->orderBy('id')->get()];
    }
}
