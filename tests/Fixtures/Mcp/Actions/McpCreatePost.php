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
 * An account-level Write that touches posts. It records the input handle() receives.
 */
#[Expose]
final class McpCreatePost extends Action
{
    protected string $description = 'Create a post for the signed-in person.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    protected bool $tenantScoped = false;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(20)->required(),
            'body' => $schema->string()->required(),
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
     * Save the post.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return Post::query()->forceCreate([...$input->all(), 'status' => 'draft', 'user_id' => $context->actor()->getAuthIdentifier()]);
    }
}
