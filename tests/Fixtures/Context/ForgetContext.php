<?php

namespace Tests\Fixtures\Context;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * Run inside RememberContext: remembers the context it runs in.
 */
#[Expose]
final class ForgetContext extends Action
{
    protected string $description = 'Run inside another action.';

    protected ?Effect $effect = Effect::Read;

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * Remember the context.
     */
    public function handle(ActionContext $context): mixed
    {
        RememberContext::$seen[] = ActionContext::current();

        return null;
    }
}
