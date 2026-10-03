<?php

namespace Tests\Fixtures\Datasets\Invalid;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use AgenticActions\Effect;
use Workbench\App\Models\Post;

/**
 * A dataset declared as a Write. Listed by its own tests only.
 */
#[Expose(agents: ['datasets'])]
final class WritingDataset extends Dataset
{
    protected string $description = 'Posts, declared as a write.';

    protected ?Effect $effect = Effect::Write;

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * A title.
     */
    public function dimensions(): array
    {
        return [Dimension::text('title', 'Title', 'title')];
    }

    /**
     * A count.
     */
    public function measures(): array
    {
        return [Measure::count('posts', 'Posts')];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }
}
