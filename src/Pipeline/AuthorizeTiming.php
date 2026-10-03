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

    /** No authorize(): denied everywhere. */
    case Missing = 'missing';
}
