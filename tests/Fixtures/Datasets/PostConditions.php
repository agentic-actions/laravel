<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Workbench\App\Models\Post;

/**
 * The workbench's posts with measures that count or sum only some rows, compared with an operator or null: posts with
 * an excerpt and without, posts that are not drafts, and the ids above 2.
 */
#[Expose]
final class PostConditions extends Dataset
{
    protected string $description = 'Posts counted by what they hold.';

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
     * Excerpted, not excerpted, not drafts, and the sum of the ids above 2.
     */
    public function measures(): array
    {
        return [
            Measure::count('excerpted', __('With an excerpt'))->where('excerpt', '!=', null),
            Measure::count('bare', __('Without an excerpt'))->where('excerpt', null),
            Measure::count('not_drafts', __('Not drafts'))->where('status', '!=', 'draft'),
            Measure::sum('later_ids', __('Ids above 2'), 'id')->where('id', '>', 2),
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
