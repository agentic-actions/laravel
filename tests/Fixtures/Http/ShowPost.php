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
 * GET posts/{post}: one post by an input key of another name, found through the tenant scope, with an alias.
 */
#[Expose(web: true)]
#[Rest('GET', 'posts/{post}', bind: ['post' => 'post_id'])]
#[Rest('GET', 'articles/{post}', bind: ['post' => 'post_id'])]
final class ShowPost extends Action
{
    protected string $description = 'Show one post.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The post to show.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->integer()->required()];
    }

    /**
     * The post's fields.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'status' => $schema->string()->required(),
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
     * The post, or not found outside the tenant.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return $context->find(Post::class, $input->integer('post_id'));
    }
}
