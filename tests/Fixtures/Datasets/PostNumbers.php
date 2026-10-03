<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Workbench\App\Models\Post;

/**
 * The workbench's posts by the day they were created, with the post ids standing in for a column of numbers: their
 * sum, average, smallest and largest, and the sum of the published ones, as money. No other dimension.
 */
#[Expose]
final class PostNumbers extends Dataset
{
    protected string $description = 'Posts and their ids by day.';

    protected string $model = Post::class;

    protected bool $tenantScoped = false;

    /**
     * Created only.
     */
    public function dimensions(): array
    {
        return [Dimension::time('created', __('Created'), 'created_at')];
    }

    /**
     * Posts, and the ids' sum, average, smallest, largest and published sum.
     */
    public function measures(): array
    {
        return [
            Measure::count('posts', __('Posts')),
            Measure::sum('id_sum', __('Id sum'), 'id'),
            Measure::avg('id_average', __('Id average'), 'id'),
            Measure::min('first_id', __('First id'), 'id'),
            Measure::max('last_id', __('Last id'), 'id'),
            Measure::sum('published_id_sum', __('Published id sum'), 'id')->where('status', 'published')->money('EUR'),
        ];
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
