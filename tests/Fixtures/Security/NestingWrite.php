<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Write with no #[Expose], queued or run in-process, whose handle() runs Inside's code.
 */
final class NestingWrite extends Action
{
    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Run Inside's code.
     */
    public function handle(ActionContext $context): string
    {
        Inside::call($context);

        return 'done';
    }
}
