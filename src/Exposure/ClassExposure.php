<?php

namespace AgenticActions\Exposure;

use AgenticActions\Action;

/**
 * The live class facts, reflected once per class per process. Class metadata cannot change inside a worker, so the
 * memo is safe under Octane.
 *
 * @internal
 */
final class ClassExposure
{
    /**
     * The memoized entries, by class.
     *
     * @var array<class-string, Entry>
     */
    private static array $entries = [];

    /**
     * The entry the class declares now.
     *
     * @param  class-string<Action>  $class
     */
    public static function of(string $class): Entry
    {
        return self::$entries[$class] ??= Entry::fromClass($class);
    }

    /**
     * Forget every memoized entry (tests).
     */
    public static function flush(): void
    {
        self::$entries = [];
    }
}
