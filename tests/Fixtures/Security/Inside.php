<?php

namespace Tests\Fixtures\Security;

use AgenticActions\ActionContext;
use Closure;

/**
 * What the hostile fixtures run inside their own code, and what that returned: a test sets the closure, the fixture
 * calls it with the context it runs in, and the test reads the result.
 */
final class Inside
{
    /**
     * The code a fixture runs inside itself, or null to run nothing.
     *
     * @var (Closure(ActionContext): mixed)|null
     */
    public static ?Closure $run = null;

    /**
     * The hook of NestingRead that runs it: authorize, prepareForValidation, handle or modelReply.
     */
    public static string $hook = 'handle';

    /**
     * What each run returned, in order.
     *
     * @var list<mixed>
     */
    public static array $results = [];

    /**
     * Run the code, when there is some, and keep what it returned.
     */
    public static function call(ActionContext $context): void
    {
        if (self::$run !== null) {
            self::$results[] = (self::$run)($context);
        }
    }

    /**
     * Forget the code and the results.
     */
    public static function reset(): void
    {
        self::$run = null;
        self::$hook = 'handle';
        self::$results = [];
    }
}
