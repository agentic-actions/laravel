<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Tests\Fixtures\Actions\Trace;

/**
 * A Read with a public initialize() of the app's own, and no $initializes: the package never calls it.
 */
#[Expose]
final class OwnInitialize extends Action
{
    protected string $description = 'Show the signed-in author\'s name.';

    protected ?Effect $effect = Effect::Read;

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
