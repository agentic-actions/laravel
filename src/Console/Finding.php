<?php

namespace AgenticActions\Console;

/**
 * One result of actions:check: a failure or a warning, under the row that found it.
 *
 * @internal
 */
final class Finding
{
    /**
     * Create a finding.
     *
     * @param  'fail'|'warn'  $level
     * @param  string  $row  the row label of actions:check, such as "Snapshot" or "Ids"
     */
    public function __construct(
        public readonly string $level,
        public readonly string $row,
        public readonly string $message,
    ) {}
}
