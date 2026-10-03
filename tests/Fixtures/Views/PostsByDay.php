<?php

namespace Tests\Fixtures\Views;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The signed-in author's posts counted per day, as a query the package limits. Offered to agents only, never on the
 * web.
 */
#[Expose(agents: ['views'])]
final class PostsByDay extends Action implements ShowsTable
{
    protected string $description = 'Posts per day of the signed-in author.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * The day, then its posts.
     */
    public function columns(): array
    {
        return [
            Column::date('day', 'Day'),
            Column::integer('posts', 'Posts'),
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
     * One row per day, oldest first.
     *
     * @return Builder<Post>
     */
    public function handle(ActionContext $context): Builder
    {
        return Post::query()
            ->where('user_id', $context->actor(User::class)->getKey())
            ->selectRaw('date(created_at) as day, count(*) as posts')
            ->groupByRaw('date(created_at)')
            ->orderBy('day');
    }
}
