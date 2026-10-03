<?php

namespace Tests\Fixtures\ConfirmationAttacks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Tests\Fixtures\Security\Inside;

/**
 * A Write offered to agents, which runs at once with no confirmation, and whose handle() runs Inside's code.
 */
#[Expose(agents: ['approvals'])]
final class NestingAgentWrite extends Action
{
    protected string $description = 'Tidy the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
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

        return 'tidied';
    }
}
