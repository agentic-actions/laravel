<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use RuntimeException;

/**
 * A Write whose handle() crashes in the worker.
 */
final class QueuedCrash extends Action
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
     * Crash.
     */
    public function handle(ActionContext $context): never
    {
        throw new RuntimeException('The queued action crashed.');
    }
}
