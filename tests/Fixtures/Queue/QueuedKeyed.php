<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Write that needs an idempotency key: it records and returns the namespaced key it would store.
 */
final class QueuedKeyed extends Action
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
     * Record and return the namespaced key.
     */
    public function handle(ActionContext $context): string
    {
        $key = $context->requireIdempotencyKey();

        Recorder::record($context, ['key' => $key]);

        return $key;
    }
}
