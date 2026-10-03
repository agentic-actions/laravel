<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Workbench\App\Models\Post;

/**
 * An account-level dataset ($tenantScoped = false) over a model its tenants own, with no scope() of its own.
 */
#[Expose(web: true, agents: ['datasets'])]
final class AccountPosts extends Dataset
{
    protected string $description = 'Posts by team.';

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * The post's team, through BelongsTo, and its status.
     */
    public function dimensions(): array
    {
        return [Dimension::text('team', 'Team', 'team.name'), Dimension::text('status', 'Status', 'status')];
    }

    /**
     * How many posts.
     */
    public function measures(): array
    {
        return [Measure::count('posts', 'Posts')];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
