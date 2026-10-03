<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Workbench\App\Models\Post;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

#[Expose]
final class ListTeamPosts extends Action
{
    protected string $description = 'List the current team\'s posts, newest first.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The team's posts, id and title only.
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
     * Any signed-in author. Membership of the team is checked before this runs.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The team's posts.
     *
     * @return array{posts: Collection<int, Post>}
     */
    public function handle(ActionContext $context): array
    {
        return ['posts' => $context->tenant(Team::class)->posts()->latest('id')->get()];
    }
}
