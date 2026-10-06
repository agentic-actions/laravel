<?php

declare(strict_types=1);

namespace Tests\Fixtures\Datasets;

use AgenticActions\Datasets\Measure;
use Carbon\CarbonImmutable;

/**
 * A measure declared in a file with strict types, as an app's dataset may be: PHP then converts no object to a string
 * on its own, so where() must take a Stringable value, such as a date, itself.
 */
final class StrictConditions
{
    /**
     * The rows created since 1 September 2026, with the date compared by an operator.
     */
    public static function recent(): Measure
    {
        return Measure::count('recent', 'Recent')->where('created_at', '>=', CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'));
    }
}
