<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * Sets $name by hand to the name its class now derives, as an app did to avoid export-c-s-v-notes, so nothing about
 * it changed.
 */
final class ExportCSVNotes extends Action
{
    protected string $name = 'export-csv-notes';

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
