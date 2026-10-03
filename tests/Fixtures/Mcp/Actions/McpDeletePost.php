<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * An account-level Destructive action: never listed or run over MCP in 0.3.
 */
#[Expose]
final class McpDeletePost extends Action
{
    protected string $description = 'Delete one of the signed-in person\'s posts.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * The post's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
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
     * Delete the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): int
    {
        return Post::query()->where('user_id', $context->actor()->getAuthIdentifier())->where('title', $input->string('title')->toString())->delete();
    }
}
