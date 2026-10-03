<?php

namespace Tests\Fixtures\OAuth\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Post;

/**
 * A tenant-scoped Write: a draft post in the current team, by the signed-in person.
 */
#[Expose]
final class CreateTeamPost extends Action
{
    protected string $description = 'Create a draft post in the current team.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    /**
     * The post's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(40)->required(),
        ];
    }

    /**
     * Any signed-in person; membership already ran.
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
        return Post::query()->forceCreate([
            'title' => $input->string('title')->value(),
            'body' => 'x',
            'status' => 'draft',
            'user_id' => $context->actor()->getAuthIdentifier(),
            'team_id' => $context->tenant()->getKey(),
        ]);
    }
}
