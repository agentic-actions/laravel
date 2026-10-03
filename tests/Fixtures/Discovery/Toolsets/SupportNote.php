<?php

namespace Tests\Fixtures\Discovery\Toolsets;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * The support toolset's lookup, which a signed-out visitor's support bot may run.
 */
#[Expose(agents: ['support'])]
final class SupportNote extends Action
{
    protected string $description = 'Look up the support hours.';

    protected ?Effect $effect = Effect::Read;

    protected bool $guests = true;

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * The hours.
     */
    public function handle(ActionContext $context): string
    {
        return '9 to 5';
    }
}
