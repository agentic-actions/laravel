<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A Destructive agent action that takes no input and writes no summary: its card holds its sentence alone, which says
 * everything the call acts on, so the Summary row passes it.
 */
#[Expose(agents: ['approvals'])]
final class ApprovalNoInput extends Action
{
    protected string $description = 'Empty the signed-in author\'s trash.';

    protected ?Effect $effect = Effect::Destructive;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The card's sentence.
     */
    public function approvalReason(ActionContext $context): string
    {
        return 'Empty your trash? This cannot be undone.';
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
