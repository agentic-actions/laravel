<?php

namespace Workbench\App\Actions;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * The dataset of docs/data.md for the blog: the signed-in author's posts by the day they were written, their team and
 * their status, counted, with the published ones and their share. A model asks with these names only, and the package
 * writes the query.
 */
#[Expose(web: true, agents: ['default'])]
final class Posts extends Dataset
{
    protected string $description = 'The signed-in author\'s posts: how many, and how many published, by day, team or status.';

    protected string $model = Post::class;

    /**
     * Only matters once config('agentic-actions.tenant.model') is set: an author's posts, in every team.
     */
    protected bool $tenantScoped = false;

    /**
     * Written (the time), the team through the post's team() and the status.
     */
    public function dimensions(): array
    {
        return [
            Dimension::time('written', __('Written'), 'created_at'),
            Dimension::text('team', __('Team'), 'team.name')->description('The team the post belongs to'),
            Dimension::text('status', __('Status'), 'status'),
        ];
    }

    /**
     * Posts, the published ones, and their share.
     */
    public function measures(): array
    {
        return [
            Measure::count('posts', __('Posts')),
            Measure::count('published', __('Published'))->where('status', 'published'),
            Measure::ratio('published_share', __('Published share'), 'published', 'posts'),
        ];
    }

    /**
     * The author's own posts.
     */
    public function scope(Builder $query, ActionContext $context): void
    {
        $query->where($query->qualifyColumn('user_id'), $context->actor(User::class)->getKey());
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }
}
