<?php

namespace Tests\Fixtures\Discovery\Segments;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * Named [save-note]: a different name from its twin, but the same route segment.
 */
final class KebabNamed extends Action
{
    protected string $name = 'save-note';

    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing to do.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
