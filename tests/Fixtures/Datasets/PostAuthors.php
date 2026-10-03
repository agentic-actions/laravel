<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Workbench\App\Models\Post;

/**
 * The tenant's posts by their author, a user no tenant owns: the relation is read in the tenant's scope unless the test
 * names it among the shared ones.
 */
#[Expose]
final class PostAuthors extends Dataset
{
    /**
     * The relations the test shares.
     *
     * @var list<string>
     */
    public static array $sharing = [];

    protected string $description = 'Posts by author.';

    protected string $model = Post::class;

    /**
     * Share what the test chose.
     */
    public function __construct()
    {
        $this->shared = self::$sharing;
    }

    /**
     * The post's author, through BelongsTo.
     */
    public function dimensions(): array
    {
        return [Dimension::text('author', 'Author', 'user.name')];
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
