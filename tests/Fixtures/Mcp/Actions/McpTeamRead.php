<?php

namespace Tests\Fixtures\Mcp\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Workbench\App\Models\Post;

/**
 * A tenant-scoped Read: the current team's post titles.
 */
#[Expose]
final class McpTeamRead extends Action
{
    protected string $description = 'List the current team\'s posts.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The team's post titles.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'titles' => $schema->array()->items($schema->string())->required(),
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
     * The team's post titles.
     *
     * @return array{titles: list<string>}
     */
    public function handle(ActionContext $context): array
    {
        return ['titles' => Post::query()->where('team_id', $context->tenant()->getKey())->orderBy('id')->pluck('title')->all()];
    }
}
