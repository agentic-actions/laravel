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
 * DELETE posts/{post}: Destructive, with no output, so a success is 204.
 */
#[Expose(web: true)]
#[Rest('DELETE', 'posts/{post}')]
final class DeletePost extends Action
{
    protected string $description = 'Delete a post.';

    protected ?Effect $effect = Effect::Destructive;

    /**
     * The post to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Delete the post found through the tenant scope.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $context->find(Post::class, $input->integer('post'))->delete();
    }
}
