<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\ActionContext;

/**
 * Records the context and input each queue fixture ran with, so a test can see what the worker rebuilt.
 */
final class Recorder
{
    /**
     * The runs, in order.
     *
     * @var list<array{context: ActionContext, input: array<string, mixed>}>
     */
    public static array $runs = [];

    /**
     * Record one run.
     *
     * @param  array<string, mixed>  $input
     */
    public static function record(ActionContext $context, array $input = []): void
    {
        self::$runs[] = ['context' => $context, 'input' => $input];
    }

    /**
     * The context of the only run.
     */
    public static function sole(): ActionContext
    {
        expect(self::$runs)->toHaveCount(1);

        return self::$runs[0]['context'];
    }

    /**
     * Forget every recorded run.
     */
    public static function reset(): void
    {
        self::$runs = [];
    }
}
