<?php

namespace AgenticActions\Console;

use AgenticActions\Discovery\Scanner;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\DuplicateActionName;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Checks every action against the package's rules and the committed exposure snapshot. It exits 1 on any failure,
 * so it can run in CI or inside an app's own test suite.
 *
 * @internal
 */
#[AsCommand(name: 'actions:check')]
final class CheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:check
        {--update : Rewrite actions.exposure.json first}
        {--production : Also require a fresh manifest}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the actions, their exposure and the committed snapshot';

    /**
     * Execute the console command.
     */
    public function handle(Scanner $scanner, Checks $checks): int
    {
        if ($this->option('update')) {
            $this->update($scanner);
        }

        $findings = $checks->run((bool) $this->option('production'));

        foreach ($findings as $finding) {
            $mark = $finding->level === 'fail' ? '<fg=red>✗</>' : '<fg=yellow>!</>';

            $this->line("  {$mark} [{$finding->row}] ".OutputFormatter::escape($finding->message));
        }

        $failures = count(array_filter($findings, fn (Finding $finding): bool => $finding->level === 'fail'));
        $warnings = count($findings) - $failures;

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("{$failures} ".Str::plural('failure', $failures).", {$warnings} ".Str::plural('warning', $warnings).'.');

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->components->warn("Every check passed, with {$warnings} ".Str::plural('warning', $warnings).'.');
        } else {
            $this->components->info('Every check passed.');
        }

        return self::SUCCESS;
    }

    /**
     * Write the snapshot from a fresh scan, and name each action it now lists under another name than the file did,
     * since the rows that run next read the new file. A scan that finds two actions under one name writes nothing; the
     * Names row reports it.
     */
    private function update(Scanner $scanner): void
    {
        try {
            $scan = $scanner->scan(
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.paths', []))),
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.classes', []))),
            );
        } catch (DuplicateActionName) {
            $this->components->warn('The snapshot was not written: two actions share a name (see the Names row).');

            return;
        }

        $stored = Snapshot::read();
        $built = Snapshot::build($scan);

        Snapshot::write($built);

        $this->components->info('Wrote '.Snapshot::path().'.');

        if (($renames = self::renames($stored, $built)) !== []) {
            $this->components->warn('Renamed: '.implode(', ', $renames).'. Update what calls an action by its old name, or keep that name with $name on its class and run php artisan actions:check --update again.');
        }
    }

    /**
     * Each class the built snapshot lists under another name than the stored one did, as "old to new", in the order of
     * the new names.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array{version: int, actions: array<string, array<string, mixed>>, agents: array<string, list<string>>}  $built
     * @return list<string>
     */
    private static function renames(?array $stored, array $built): array
    {
        $before = [];

        foreach (is_array($stored['actions'] ?? null) ? $stored['actions'] : [] as $name => $row) {
            if (is_array($row) && is_string($row['class'] ?? null)) {
                $before[$row['class']] = (string) $name;
            }
        }

        $renames = [];

        foreach ($built['actions'] as $name => $row) {
            $previous = is_string($row['class'] ?? null) ? ($before[$row['class']] ?? null) : null;

            if ($previous !== null && $previous !== (string) $name) {
                $renames[] = "{$previous} to {$name}";
            }
        }

        return $renames;
    }
}
