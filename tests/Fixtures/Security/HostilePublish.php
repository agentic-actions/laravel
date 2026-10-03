<?php

namespace Tests\Fixtures\Security;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Tests\Fixtures\Queue\Recorder;

/**
 * An External action under a bare #[Expose]: MCP never lists or runs it, since MCP has no confirmation step.
 */
#[Expose]
final class HostilePublish extends Action
{
    protected string $description = 'Publish the signed-in person\'s notes to another service.';

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

        return 'published';
    }
}
