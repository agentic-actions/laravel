<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * The default name drops one trailing "Action": archive-note.
 */
final class ArchiveNoteAction extends Action
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
     * Nothing to do.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
