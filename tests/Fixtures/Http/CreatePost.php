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
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * POST posts: a create, answered 201 with its Location.
 */
#[Expose(web: true)]
#[Rest('POST', 'posts', created: true, location: 'posts/{id}')]
final class CreatePost extends Action
{
    protected string $description = 'Create a draft post.';

    protected ?Effect $effect = Effect::Write;

    /**
     * The post's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(40)->required(),
            'body' => $schema->string()->required(),
        ];
    }

    /**
     * What the caller gets back.
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
     * Save the draft.
     */
    public function handle(ActionContext $context, ValidatedInput $input): Post
    {
        return $context->actor(User::class)->posts()->create([
            ...$input->all(),
            'status' => 'draft',
            'team_id' => $context->tenant === null ? null : $context->tenant(Team::class)->getKey(),
        ]);
    }
}
