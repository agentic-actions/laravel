<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Tests\Fixtures\Queue\ReadThatQueues;

/**
 * A Read that queues another Read, ReadThatQueues, which queues whatever its own static names when the worker runs it.
 */
final class ReadQueuesRead extends Action
{
    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Queue the other Read.
     */
    public function handle(ActionContext $context): string
    {
        ReadThatQueues::dispatch([], $context);

        return 'queued';
    }
}
