<?php

namespace Tests\Feature\Feed\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * An action that takes the change feed's reserved name, which Actions::routes() refuses.
 */
#[Expose]
final class FeedNamedChanges extends Action
{
    protected string $name = '_changes';

    protected string $description = 'Collide with the change feed.';

    protected ?Effect $effect = Effect::Write;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): null
    {
        return null;
    }
}
