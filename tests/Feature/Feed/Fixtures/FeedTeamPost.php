<?php

namespace Tests\Feature\Feed\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * A tenant-scoped Write touching posts: its touch lands in the team's bucket, for every member's open page.
 */
#[Expose]
final class FeedTeamPost extends Action
{
    protected string $description = 'Save a post in the current team.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    /**
     * The post's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['title' => $schema->string()->required()];
    }

    /**
     * Any signed-in member.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Save the post in the team; the caller gets its id.
     */
    public function handle(ActionContext $context, ValidatedInput $input): int
    {
        return $context->actor(User::class)->posts()->create([
            'title' => $input->string('title')->toString(),
            'body' => 'team',
            'status' => 'draft',
            'team_id' => $context->tenant(Team::class)->getKey(),
        ])->getKey();
    }
}
