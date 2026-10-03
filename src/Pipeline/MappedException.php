<?php

namespace AgenticActions\Pipeline;

use AgenticActions\Refusal;
use Illuminate\Validation\ValidationException;

/**
 * A throwable from inside the pipeline, mapped to an outcome kind.
 *
 * @internal
 */
final class MappedException
{
    /**
     * Create a mapped exception.
     */
    public function __construct(
        public readonly OutcomeKind $kind,
        public readonly ?Refusal $refusal = null,
        public readonly ?ValidationException $validation = null,
    ) {}
}
