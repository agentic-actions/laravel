<?php

namespace AgenticActions\Contracts;

use AgenticActions\ActionContext;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;

/**
 * Answers calls instead of the pipeline while bound. The testing kit implements it.
 *
 * @internal
 */
interface InterceptsActions
{
    /**
     * Record the call and return the faked outcome.
     *
     * @param  array<string, mixed>  $input
     */
    public function respond(Entry $entry, array $input, ActionContext $context): Outcome;
}
