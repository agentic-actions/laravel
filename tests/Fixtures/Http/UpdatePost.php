<?php

namespace Tests\Fixtures\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Attributes\Rest;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * PATCH posts/{post}: the path parameter named as the input key.
 */
#[Expose(web: true)]
#[Rest('PATCH', 'posts/{post}')]
final class UpdatePost extends Action
{
    protected string $description = 'Rename a post.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The post and its new title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->required(),
            'title' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * The post's fields.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
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
     * Rename the post found through the tenant scope.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return tap($context->find(Post::class, $input->integer('post')))->update(['title' => $input->string('title')->toString()]);
    }
}
