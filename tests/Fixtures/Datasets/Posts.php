<?php

namespace Tests\Fixtures\Datasets;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Post;

/**
 * The workbench's posts as a dataset: counted by the day they were created, by their team (through BelongsTo) and by
 * status (an enum), with the number of posts and of authors, the published ones, and two ratios of them. Its zone and
 * the conditions its scope() adds are the test's. A bare #[Expose], so it opens the web and MCP without laravel/ai too.
 */
#[Expose]
final class Posts extends Dataset
{
    /**
     * The conditions scope() adds, if a test chooses any.
     *
     * @var (Closure(Builder<Post>, ActionContext): mixed)|null
     */
    public static ?Closure $scope = null;

    /**
     * The zone the days are counted in, if a test chooses one.
     */
    public static string $zone = '';

    protected string $description = 'Posts and their authors, by day, team and status.';

    protected string $model = Post::class;

    /**
     * Set, to show a dataset never asks: its call is generated.
     */
    protected bool $askForMissing = true;

    /**
     * Count the days in the zone the test chose, or in app.timezone.
     */
    public function __construct()
    {
        $this->timezone = self::$zone;
    }

    /**
     * Created (the time), team and status.
     */
    public function dimensions(): array
    {
        return [
            Dimension::time('created', __('Created'), 'created_at'),
            Dimension::text('team', __('Team'), 'team.name')->description('The team the post belongs to'),
            Dimension::enum('status', __('Status'), 'status', PostStatus::class),
        ];
    }

    /**
     * Posts, authors, published posts, the published share, and posts per published one, which has no value for a
     * group with nothing published.
     */
    public function measures(): array
    {
        return [
            Measure::count('posts', __('Posts'))->description('The number of posts'),
            Measure::countDistinct('authors', __('Authors'), 'user_id'),
            Measure::count('published', __('Published'))->where('status', PostStatus::Published),
            Measure::ratio('published_share', __('Published share'), 'published', 'posts'),
            Measure::ratio('posts_per_published', __('Posts per published'), 'posts', 'published'),
        ];
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
