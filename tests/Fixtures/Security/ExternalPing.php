<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Tests\Fixtures\Queue\Recorder;

/**
 * An External action with no #[Expose]: model-driven calls never reach it, whatever context they build.
 */
final class ExternalPing extends Action
{
    protected ?Effect $effect = Effect::External;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Record the run.
     */
    public function handle(ActionContext $context): string
    {
        Recorder::record($context);

        return 'sent';
    }
}
