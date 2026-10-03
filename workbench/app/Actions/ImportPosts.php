<?php

namespace Workbench\App\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Drafts a batch of posts in the current team. The token client queues it (ImportController), and an MCP client on the
 * team's path calls it directly; either way, open pages of the team hear of it through the change feed.
 */
#[Expose]
final class ImportPosts extends Action
{
    protected string $description = 'Import draft posts into the current team, one per title.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['posts'];

    /**
     * The titles to draft.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'titles' => $schema->array()->items($schema->string()->max(120))->min(1)->max(20)->required(),
        ];
    }

    /**
     * How many drafts were written.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'imported' => $schema->integer()->required(),
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
     * Draft one post per title, as the actor, in the team.
     *
     * @return array{imported: int}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $team = $context->tenant(Team::class);
        $author = $context->actor(User::class);

        foreach ($input->array('titles') as $title) {
            $author->posts()->create(['title' => $title, 'body' => '', 'status' => 'draft', 'team_id' => $team->getKey()]);
        }

        return ['imported' => count($input->array('titles'))];
    }
}
