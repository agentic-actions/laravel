<?php

namespace Tests\Fixtures\Http;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Attributes\Rest;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * POST posts/{post}/publish: a verb as a sub-resource, refusing a post already published.
 */
#[Expose(web: true)]
#[Rest('POST', 'posts/{post}/publish')]
final class PublishPost extends Action
{
    protected string $description = 'Publish a draft post.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The post to publish.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post' => $schema->integer()->required()];
    }

    /**
     * The new status.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Publish the draft found through the tenant scope.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        $post = $context->find(Post::class, $input->integer('post'));

        if ($post->status === 'published') {
            throw Refusal::make('The post is already published.')->status(409);
        }

        return tap($post)->update(['status' => 'published']);
    }
}
