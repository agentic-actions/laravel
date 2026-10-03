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
 * A tenant-scoped Destructive action, which MCP never lists or runs, so no path asks for its scope.
 */
#[Expose]
final class DeleteTeamPost extends Action
{
    protected string $description = 'Delete a post of the current team.';

    protected ?Effect $effect = Effect::Destructive;

    protected array $touches = ['posts'];

    /**
     * The post's title.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
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
     * Delete the team's posts with that title.
     */
    public function handle(ActionContext $context, ValidatedInput $input): int
    {
        return Post::query()->where('team_id', $context->tenant()->getKey())->where('title', $input->string('title')->value())->delete();
    }
}
