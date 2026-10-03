<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use RuntimeException;

/**
 * Crashes in handle().
 */
#[Expose(web: true)]
final class CrashingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Crash.
     */
    public function handle(ActionContext $context): never
    {
        throw new RuntimeException('The note crashed.');
    }
}
