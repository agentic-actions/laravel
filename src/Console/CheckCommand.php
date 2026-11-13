<?php

namespace AgenticActions\Console;

use AgenticActions\Discovery\Group;
use AgenticActions\Discovery\Groups;
use AgenticActions\Discovery\Snapshot;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
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
        {--production : Also require a fresh manifest}
        {--group=* : Update and compare only these discovery groups\' snapshots}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the actions, their exposure and the committed snapshot';

    /**
     * Execute the console command.
     */
    public function handle(Groups $groups, Checks $checks): int
    {
        /** @var list<string> $only */
        $only = array_values(array_map(strval(...), (array) $this->option('group')));

        if (($unknown = Groups::unknown($only)) !== []) {
            $this->components->error('There is no discovery group ['.implode(', ', $unknown).'].');

            return self::FAILURE;
        }

        if ($this->option('update')) {
            $this->update($groups, $only);
        }

        $findings = $checks->run((bool) $this->option('production'), $only);

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
     * since the rows that run next read the new file. With discovery groups, each installed group writes its own file
     * from its own scan. A scan that finds two actions under one name writes nothing; the Names row reports it.
     *
     * @param  list<string>  $only  the groups to write; every group when empty
     */
    private function update(Groups $groups, array $only): void
    {
        try {
            $scan = $groups->scan();
            $scans = Groups::grouped() ? $groups->scans() : [Group::DEFAULT => $scan];
            $named = Groups::named($only);
        } catch (DuplicateActionName) {
            $this->components->warn('The snapshot was not written: two actions share a name (see the Names row).');

            return;
        } catch (MisconfiguredDiscovery) {
            $this->components->warn('The snapshot was not written: discovery.groups is misconfigured (see the Discovery row).');

            return;
        }

        foreach ($named as $group) {
            // A group whose roots are missing is not installed here: its snapshot is neither written nor checked.
            if (! isset($scans[$group->name])) {
                $this->components->info("Skipped the {$group->name} group: none of its paths exists.");

                continue;
            }

            $path = $group->snapshotPath();
            $stored = Snapshot::read($path);
            $built = Snapshot::build($scans[$group->name]);

            Snapshot::write($built, $path);

            $this->components->info('Wrote '.$path.'.');

            if (($renames = self::renames($stored, $built)) !== []) {
                $this->components->warn('Renamed: '.implode(', ', $renames).'. Update what calls an action by its old name, or keep that name with $name on its class and run php artisan actions:check --update again.');
            }
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
