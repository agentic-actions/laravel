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
     * @param  array<class-string, list<string>>  $agents  the #[UseToolset] names of every class carrying #[UseToolset] or #[DeferToolset], none for one that carries only the second, sorted
     * @param  list<string>  $directories  the absolute directories walked
     * @param  list<string>  $warnings  "{class} could not be loaded: {first line of the error}", one per skipped class
     * @param  list<class-string>  $pageContext  classes carrying #[WithPageContext], sorted
     * @param  array<class-string, list<string>>  $deferred  the #[DeferToolset] names of every class carrying it, sorted
     */
    public function __construct(
        public readonly array $actions,
        public readonly array $agents,
        public readonly array $directories,
        public readonly array $warnings = [],
        public readonly array $pageContext = [],
        public readonly array $deferred = [],
    ) {}

    /**
     * Every toolset an agent receives, loaded on every step or found through tool search.
     *
     * @return list<string>
     */
    public function toolsets(string $agent): array
    {
        return array_values(array_unique([...$this->agents[$agent] ?? [], ...$this->deferred[$agent] ?? []]));
    }

    /**
     * Every toolset some agent receives, loaded on every step or found through tool search.
     *
     * @return list<string>
     */
    public function received(): array
    {
        return array_values(array_unique(array_merge([], ...array_values($this->agents), ...array_values($this->deferred))));
    }

    /**
     * The agents as the snapshot and the manifest record them: each one's #[UseToolset] names, and both lists, "use"
     * and "defer", for one that carries #[DeferToolset], so a toolset added to either attribute is a reviewed diff.
     *
     * @return array<class-string, list<string>|array{use: list<string>, defer: list<string>}>
     */
    public function recordedAgents(): array
    {
        $recorded = [];

        foreach ($this->agents as $agent => $toolsets) {
            $recorded[$agent] = isset($this->deferred[$agent]) ? ['use' => $toolsets, 'defer' => $this->deferred[$agent]] : $toolsets;
        }

        return $recorded;
    }

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
