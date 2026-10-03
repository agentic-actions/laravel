<?php

namespace Tests\Fixtures\Misconfigured;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A Destructive action named for agents: no longer a mistake. Its toolset receives it, and each agent call waits for
 * a person to confirm it.
 */
#[Expose(agents: ['x'])]
final class DestructiveForAgents extends Action
{
    protected string $description = 'Delete everything.';

    protected ?Effect $effect = Effect::Destructive;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
