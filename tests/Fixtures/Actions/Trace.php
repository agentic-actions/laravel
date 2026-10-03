<?php

namespace Tests\Fixtures\Actions;

/**
 * Records the hooks traced fixtures run, in order, so a test can see which steps ran. It never names the package, so
 * the scanner skips it.
 */
final class Trace
{
    /**
     * The hooks that ran, in order.
     *
     * @var list<string>
     */
    public static array $calls = [];

    /**
     * Record one hook.
     */
    public static function record(string $call): void
    {
        self::$calls[] = $call;
    }

    /**
     * Forget every recorded hook.
     */
    public static function reset(): void
    {
        self::$calls = [];
    }
}
