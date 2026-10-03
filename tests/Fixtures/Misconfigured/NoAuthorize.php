<?php

namespace Tests\Fixtures\Misconfigured;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * No authorize(): denied everywhere.
 */
#[Expose(web: true)]
final class NoAuthorize extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Never reached.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
