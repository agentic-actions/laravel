<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Tests\Fixtures\Actions\Trace;
use Workbench\App\Models\Post;

/**
 * A dataset that declares $initializes and initialize(): its call is generated, so the package never calls it.
 */
#[Expose]
final class InitializingDataset extends Dataset
{
    protected string $description = 'Posts by status.';

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * Read only on an action that is not a dataset.
     *
     * @var list<string>
     */
    protected array $initializes = ['share_links'];

    /**
     * Posts by status.
     */
    public function dimensions(): array
    {
        return [Dimension::text('status', 'Status', 'status')];
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

    /**
     * Record the step, if it ever runs.
     */
    public function initialize(): void
    {
        Trace::record('initialize');
    }
}
