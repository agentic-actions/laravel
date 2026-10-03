<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Destructive action queued through Action::dispatch(): model surfaces never reach it, so a job queued from one is
 * refused in the worker.
 */
final class QueuedDestructive extends Action
{
    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context): string
    {
        Recorder::record($context);

        return 'destroyed';
    }
}
