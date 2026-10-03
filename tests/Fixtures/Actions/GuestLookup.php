<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A Read a model may run with no actor, because it sets $guests.
 */
final class GuestLookup extends Action
{
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
     * Answer the lookup.
     */
    public function handle(ActionContext $context): string
    {
        return 'looked up';
    }
}
