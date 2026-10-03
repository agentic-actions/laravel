<?php

namespace Tests\Fixtures\Datasets\Invalid;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use Closure;

/**
 * A dataset over OddPost whose declarations, zone and range the test chooses, to declare what a dataset cannot answer.
 * Listed by its own tests only.
 */
#[Expose(agents: ['datasets'])]
final class BadDeclarations extends Dataset
{
    /**
     * The dimensions to declare.
     *
     * @var (Closure(): list<Dimension>)|null
     */
    public static ?Closure $dimensions = null;

    /**
     * The measures to declare.
     *
     * @var (Closure(): list<Measure>)|null
     */
    public static ?Closure $measures = null;

    /**
     * The zone and the range to declare.
     *
     * @var array{0: string, 1: string}
     */
    public static array $days = ['', '-30d'];

    /**
     * The relations the test shares.
     *
     * @var list<string>
     */
    public static array $sharing = [];

    protected string $description = 'Posts, as the test declares them.';

    protected string $model = OddPost::class;

    protected bool $tenantScoped = false;

    /**
     * Take the test's zone and range.
     */
    public function __construct()
    {
        [$this->timezone, $this->range] = self::$days;
        $this->shared = self::$sharing;
    }

    /**
     * The test's dimensions, or a title.
     */
    public function dimensions(): array
    {
        return self::$dimensions === null ? [Dimension::text('title', 'Title', 'title')] : (self::$dimensions)();
    }

    /**
     * The test's measures, or a count.
     */
    public function measures(): array
    {
        return self::$measures === null ? [Measure::count('posts', 'Posts')] : (self::$measures)();
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }
}
