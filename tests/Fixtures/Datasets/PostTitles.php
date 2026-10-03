<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Workbench\App\Models\Post;

/**
 * Posts by their free-text title over time, a text a collation may compare case- or accent-insensitively, and by
 * their team's name through BelongsTo: a filter value on either is any text a caller sends.
 */
#[Expose]
final class PostTitles extends Dataset
{
    protected string $description = 'Posts by title.';

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * When a post was created, its title, and its team.
     */
    public function dimensions(): array
    {
        return [
            Dimension::time('created', 'Created', 'created_at'),
            Dimension::text('title', 'Title', 'title'),
            Dimension::text('team', 'Team', 'team.name'),
        ];
    }

    /**
     * How many posts, and by how many authors.
     */
    public function measures(): array
    {
        return [Measure::count('posts', 'Posts'), Measure::countDistinct('authors', 'Authors', 'user_id')];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
