<?php

namespace AgenticActions\Testing;

use AgenticActions\Action;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\ClassExposure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Pins what one toolset holds, so widening it is a reviewed change in a test.
 *
 * @internal Reached through Actions::assertToolset() and ActionAssertions::assertToolset().
 */
final class ToolsetAssertion
{
    /**
     * Fail unless the toolset holds exactly these actions, naming the extra and the missing ones.
     *
     * @param  list<string>  $names
     */
    public static function assert(string $toolset, array $names): void
    {
        $actual = self::names($toolset);
        $expected = array_values($names);

        sort($expected);

        $extra = array_values(array_diff($actual, $expected));
        $missing = array_values(array_diff($expected, $actual));

        Assert::assertSame($expected, $actual, sprintf(
            'The [%s] toolset does not hold exactly the expected actions. Extra: %s. Missing: %s.',
            $toolset,
            $extra === [] ? 'none' : implode(', ', $extra),
            $missing === [] ? 'none' : implode(', ', $missing),
        ));
    }

    /**
     * The names of every discovered action whose class puts it in the toolset now, sorted.
     *
     * @return list<string>
     */
    private static function names(string $toolset): array
    {
        $names = [];

        foreach (app(ActionRegistry::class)->all() as $candidate) {
            try {
                $entry = is_subclass_of($candidate->class, Action::class) ? ClassExposure::of($candidate->class) : null;
            } catch (Throwable) {
                $entry = null;
            }

            if ($entry !== null && in_array($toolset, $entry->toolsets, true)) {
                $names[] = $entry->name;
            }
        }

        sort($names);

        return $names;
    }
}
