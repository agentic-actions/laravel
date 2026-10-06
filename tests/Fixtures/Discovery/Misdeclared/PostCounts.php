<?php

namespace Tests\Fixtures\Discovery\Misdeclared;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Measure;
use Closure;
use Workbench\App\Models\Post;

/**
 * A dataset on every surface a Read opens, so in the default toolset and on MCP beside the working actions there,
 * whose measures() the test declares, as one the package refuses. Listed by its own tests only.
 */
#[Expose]
final class PostCounts extends Dataset
{
    /**
     * The measures to declare.
     *
     * @var (Closure(): list<Measure>)|null
     */
    public static ?Closure $measures = null;

    protected string $description = 'Posts, counted as the test declares them.';

    protected string $model = Post::class;

    /**
     * None.
     */
    public function dimensions(): array
    {
        return [];
    }

    /**
     * The test's measures, or a count.
     */
    public function measures(): array
    {
        return self::$measures === null ? [Measure::count('posts', 'Posts')] : (self::$measures)();
    }

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }
}
