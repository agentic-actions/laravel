<?php

namespace Tests\Fixtures\Datasets\Invalid;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use AgenticActions\Effect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Post;

/**
 * A dataset declared as a Write, a declaration error, whose scope() writes: no door may serve it.
 */
#[Expose(web: true, agents: ['datasets'])]
final class WritingScope extends Dataset
{
    protected string $description = 'Posts, declared as a write.';

    protected ?Effect $effect = Effect::Write;

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * A post's title.
     */
    public function dimensions(): array
    {
        return [Dimension::text('title', 'Title', 'title')];
    }

    /**
     * How many posts.
     */
    public function measures(): array
    {
        return [Measure::count('posts', 'Posts')];
    }

    /**
     * Writes, which a Read never may.
     *
     * @param  Builder<Model>  $query
     */
    public function scope(Builder $query, ActionContext $context): void
    {
        Post::query()->update(['title' => 'Written by a dataset']);
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
