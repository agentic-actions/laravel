<?php

namespace Tests\Fixtures\Deferred;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;

/**
 * A Read in two toolsets, default and reports, that a signed-out visitor may run too: an agent that loads one of them
 * and defers the other receives it once.
 */
#[Expose(agents: ['default', 'reports'])]
final class NoteStats extends Action
{
    protected string $description = 'Count the notes.';

    protected ?Effect $effect = Effect::Read;

    protected bool $guests = true;

    /**
     * Anyone.
     */
    public function authorize(ActionContext $context): bool
    {
        return true;
    }

    /**
     * A fixed count.
     */
    public function handle(ActionContext $context): string
    {
        return '3 notes';
    }
}
