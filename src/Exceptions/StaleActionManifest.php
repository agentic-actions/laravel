<?php

namespace AgenticActions\Exceptions;

use RuntimeException;

/**
 * A production process scanned because the manifest was missing or stale. Reported, never thrown.
 *
 * @internal
 */
final class StaleActionManifest extends RuntimeException
{
    //
}
