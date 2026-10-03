<?php

namespace Tests\Fixtures\Views\Invalid;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * An action that takes the tables' refresh route's reserved name, which Actions::routes() refuses. Listed by its own
 * test only.
 */
#[Expose]
final class ViewsNamed extends Action
{
    protected string $name = '_views';

    protected string $description = 'Collide with the tables\' refresh.';

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
