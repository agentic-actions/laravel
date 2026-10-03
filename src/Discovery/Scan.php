<?php

namespace AgenticActions\Discovery;

use AgenticActions\Exposure\Entry;

/**
 * One pass of the scanner: the actions and agents it found, where it looked, and what it could not load. It records
 * exposure errors and never throws for them.
 *
 * @internal
 */
final class Scan
{
    /**
     * Create a scan result.
     *
     * @param  array<string, Entry>  $actions  keyed by name, sorted
     * @param  array<class-string, list<string>>  $agents  #[UseToolset] classes, sorted
     * @param  list<string>  $directories  the absolute directories walked
     * @param  list<string>  $warnings  "{class} could not be loaded: {first line of the error}", one per skipped class
     * @param  list<class-string>  $pageContext  classes carrying #[WithPageContext], sorted
     */
    public function __construct(
        public readonly array $actions,
        public readonly array $agents,
        public readonly array $directories,
        public readonly array $warnings = [],
        public readonly array $pageContext = [],
    ) {}

    /**
     * Every entry's errors, as "{class}: {error}".
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->actions as $entry) {
            foreach ($entry->errors as $error) {
                $errors[] = "{$entry->class}: {$error}";
            }
        }

        return $errors;
    }
}
