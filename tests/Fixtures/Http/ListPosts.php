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

/**
 * GET posts: the collection, filtered by the query string, in the tenant when there is one.
 */
#[Expose(web: true)]
#[Rest('GET', 'posts')]
final class ListPosts extends Action
{
    protected string $description = 'List the posts.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The filter.
     */
    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->enum(['draft', 'published'])->nullable()];
    }

    /**
     * The posts, id and title only.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['data' => $schema->array()->items($schema->object([
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ]))->required()];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The posts.
     *
     * @return array<string, mixed>
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $query = Post::query()->orderBy('id');

        if ($context->tenant !== null) {
            $query->where('team_id', $context->tenant(Team::class)->getKey());
        }

        if ($input->filled('status')) {
            $query->where('status', $input->string('status')->toString());
        }

        return ['data' => $query->get()];
    }
}
