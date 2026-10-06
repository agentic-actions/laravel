<?php

namespace AgenticActions\Pipeline;

use AgenticActions\ActionContext;

/**
 * The contexts of the actions running now, innermost last: a scoped binding, so it never outlives its request or job
 * under Octane or a long-lived worker. ActionContext::current() reads it.
 *
 * @internal
 */
final class RunningContexts
{
    /** @var list<ActionContext> */
    private array $stack = [];

    /**
     * Enter a run.
     */
    public function push(ActionContext $context): void
    {
        $this->stack[] = $context;
    }

    /**
     * Leave the innermost run.
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * The innermost run's context, or null outside any run.
     */
    public function current(): ?ActionContext
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
