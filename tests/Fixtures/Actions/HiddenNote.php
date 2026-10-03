<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * shouldRegister() says no: it reads exactly like an unknown action.
 */
#[Expose]
final class HiddenNote extends Action
{
    protected string $description = 'A note nobody can see.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Never registered.
     */
    public function shouldRegister(ActionContext $context): bool
    {
        Trace::record('shouldRegister');

        return false;
    }

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        Trace::record('authorize');

        return $context->actor !== null;
    }

    /**
     * Never reached.
     */
    public function handle(ActionContext $context): mixed
    {
        Trace::record('handle');

        return null;
    }
}
