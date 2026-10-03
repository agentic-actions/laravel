<?php

namespace Tests\Fixtures\Discovery\Segments;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * Named [saveNote]: a different name from its twin, but the same route segment.
 */
final class CamelNamed extends Action
{
    protected string $name = 'saveNote';

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
