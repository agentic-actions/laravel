<?php

namespace Tests\Fixtures\Queue;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Read whose handle() queues another action: a Write is refused before anything is queued, a Read is queued.
 */
final class ReadThatQueues extends Action
{
    /**
     * The action handle() queues.
     *
     * @var class-string<Action>
     */
    public static string $queues = QueuedWrite::class;

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
     * Queue the chosen action.
     */
    public function handle(ActionContext $context): string
    {
        (self::$queues)::dispatch(['title' => 'From a Read'], $context);

        return 'queued';
    }
}
