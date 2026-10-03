<?php

namespace AgenticActions\Pipeline;

use AgenticActions\Outcome;
use RuntimeException;

/**
 * Stops the pipeline with an outcome; used between Runner::admit() and the HTTP bridge.
 *
 * @internal
 */
final class Halt extends RuntimeException
{
    /**
     * Carry the outcome that stopped the pipeline.
     */
    public function __construct(public readonly Outcome $outcome)
    {
        parent::__construct('The action pipeline stopped with ['.$outcome->kind()->value.'].');
    }
}
