<?php

namespace Tests\Fixtures\Initialize;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Tests\Fixtures\Actions\Trace;

/**
 * A Write that declares $initializes and initialize(), which only a Read runs: the package never calls it.
 */
#[Expose]
final class InitializingWrite extends Action
{
    protected string $description = 'Save the signed-in author\'s settings.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Read only on a Read action.
     *
     * @var list<string>
     */
    protected array $initializes = ['share_links'];

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the step, if it ever runs.
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
