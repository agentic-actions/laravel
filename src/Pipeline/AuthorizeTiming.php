<?php

namespace AgenticActions\Pipeline;

/**
 * When an action's authorize() runs.
 *
 * @internal
 */
enum AuthorizeTiming: string
{
    /** Before any input is read (step 3). */
    case Early = 'early';

    /** After validation, with the validated input (step 7). */
    case Late = 'late';

    /**
     * Both: before any input is read with null (step 3, and when a tool is listed), then after validation with the
     * validated input (step 7). An authorize() whose ValidatedInput may be null.
     */
    case Both = 'both';

    /** No authorize(): denied everywhere. */
    case Missing = 'missing';

    /**
     * Whether authorize() runs before any input is read.
     */
    public function early(): bool
    {
        return $this === self::Early || $this === self::Both;
    }

    /**
     * Whether authorize() sees the validated input.
     */
    public function takesInput(): bool
    {
        return $this === self::Late || $this === self::Both;
    }
}
