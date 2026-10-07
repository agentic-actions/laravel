<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Tests\Fixtures\Actions\Trace;

/**
 * A Write with a public initialize() of the app's own, and no $initializes: only a Read runs initialize(), so
 * actions:check has nothing to say about it.
 */
#[Expose]
final class OwnInitializeOnWrite extends Action
{
    protected string $description = 'Mark the signed-in author as active.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The app's own method, which handle() does not call.
     */
    public function initialize(): void
    {
        Trace::record('initialize');
    }

    /**
     * Record the step.
     */
    public function handle(): null
    {
        Trace::record('handle');

        return null;
    }
}
