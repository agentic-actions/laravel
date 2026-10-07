<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Closure;

/**
 * A Read that names share_links in $initializes, and whose initialize() and handle() run what the test gives them.
 */
final class InitializeProbe extends Action
{
    /**
     * What initialize() runs.
     */
    public static ?Closure $initializing = null;

    /**
     * What handle() runs.
     */
    public static ?Closure $handling = null;

    /**
     * How many times initialize() ran to the end.
     */
    public static int $initialized = 0;

    protected ?Effect $effect = Effect::Read;

    /**
     * The table initialize() may add rows to.
     *
     * @var list<string>
     */
    protected array $initializes = ['share_links'];

    /**
     * Put every static back to its default.
     */
    public static function reset(): void
    {
        self::$initializing = null;
        self::$handling = null;
        self::$initialized = 0;
    }

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Run what the test gave, then count the run.
     */
    public function initialize(ActionContext $context): void
    {
        (self::$initializing ?? fn (): null => null)($context);

        self::$initialized++;
    }

    /**
     * Run what the test gave.
     */
    public function handle(ActionContext $context): mixed
    {
        return (self::$handling ?? fn (): null => null)($context);
    }
}
