<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Posts whose tenant is their model's global scope rather than the package's tenancy, counted by status and team. Its
 * scope() runs the conditions a test chooses.
 */
#[Expose]
final class GlobalScopedPosts extends Dataset
{
    /**
     * The conditions scope() adds, if a test chooses any.
     *
     * @var (Closure(Builder<ScopedPost>, ActionContext): mixed)|null
     */
    public static ?Closure $scope = null;

    protected string $description = 'The team\'s posts by status.';

    protected string $model = ScopedPost::class;

    protected bool $tenantScoped = false;

    /**
     * Status, and the team through its own model.
     */
    public function dimensions(): array
    {
        return [Dimension::text('status', __('Status'), 'status'), Dimension::text('team', __('Team'), 'team.name')];
    }

    /**
     * Posts only.
     */
    public function measures(): array
    {
        return [Measure::count('posts', __('Posts'))];
    }

    /**
     * The test's conditions, if any.
     */
    public function scope(Builder $query, ActionContext $context): void
    {
        if (self::$scope !== null) {
            (self::$scope)($query, $context);
        }
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
