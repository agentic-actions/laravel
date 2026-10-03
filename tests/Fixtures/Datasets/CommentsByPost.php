<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;

/**
 * The tenant's comments by the title of the post each is on.
 */
#[Expose]
final class CommentsByPost extends Dataset
{
    protected string $description = 'Comments by post.';

    protected string $model = Comment::class;

    /**
     * The post's title, through BelongsTo.
     */
    public function dimensions(): array
    {
        return [Dimension::text('post', 'Post', 'post.title')];
    }

    /**
     * How many comments.
     */
    public function measures(): array
    {
        return [Measure::count('comments', 'Comments')];
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
