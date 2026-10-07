<?php

namespace AgenticActions\Testing;

use AgenticActions\Ai\ActionTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Pest\Expectation;
use PHPUnit\Framework\Assert;

/**
 * The package's Pest expectations. Nothing registers when Pest is not loaded. TestingServiceProvider calls register()
 * while the app runs its tests; the expectations it registers (toContainActionTool) are the kit's public part.
 *
 * @internal
 */
final class Expectations
{
    /**
     * Register the Pest expectations when Pest is loaded. Each test's application registers them again, under the
     * same names. toContainActionTool looks at the top level of the tools and inside a tool-search group.
     */
    public static function register(): void
    {
        if (! function_exists('expect') || ! class_exists(Expectation::class)) {
            return;
        }

        expect()->extend('toContainActionTool', function (string $name): Expectation {
            $tools = [];

            foreach (is_iterable($this->value) ? $this->value : [] as $tool) {
                foreach ($tool instanceof ToolSearch ? $tool->tools : [$tool] as $inner) {
                    $tools[] = $inner;
                }
            }

            $names = array_map(fn ($tool) => $tool->name(), array_filter($tools, fn ($tool) => $tool instanceof ActionTool));

            Assert::assertContains($name, $names, "No action tool [{$name}] among: ".implode(', ', $names));

            return $this;
        });
    }
}
