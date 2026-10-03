<?php

namespace Tests\Fixtures\Datasets\Invalid;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;

/**
 * A dataset that names no model. Listed by its own tests only.
 */
#[Expose(agents: ['datasets'])]
final class NoModel extends Dataset
{
    protected string $description = 'Rows of no model.';

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
        return [Measure::count('rows', 'Rows')];
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }
}
