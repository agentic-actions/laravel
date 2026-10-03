<?php

namespace Tests\Fixtures\Views;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use Illuminate\Routing\Controllers\HasMiddleware;
use Workbench\App\Models\Post;

/**
 * A table whose class declares its own middleware, which its generated route applies and a refresh cannot.
 */
#[Expose(web: true, agents: ['views'])]
final class GuardedPosts extends Action implements HasMiddleware, ShowsTable
{
    protected string $description = 'Every post, behind the class\'s own throttle.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * The class's own middleware.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['throttle:1,1'];
    }

    /**
     * The title.
     */
    public function columns(): array
    {
        return [Column::text('title', __('Title'))];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Every post.
     *
     * @return list<array{title: string}>
     */
    public function handle(): array
    {
        return Post::query()->get(['title'])->map(fn (Post $post): array => ['title' => $post->title])->all();
    }
}
