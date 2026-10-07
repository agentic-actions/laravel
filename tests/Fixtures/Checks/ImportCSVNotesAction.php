<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * No $name, a run of capitals in its class name and a trailing "Action" a derived name drops: import-csv-notes, which
 * 0.9.0-beta.3 and earlier named import-c-s-v-notes.
 */
final class ImportCSVNotesAction extends Action
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
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
