<?php

namespace AgenticActions\TypeScript;

use AgenticActions\Discovery\Groups;
use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Writes the typed action definitions the npm client imports, or checks that the committed file is current. With
 * discovery groups, each installed group that names a typescript file gets the definitions of its own actions only.
 *
 * @internal
 */
#[AsCommand(name: 'actions:typescript')]
final class TypeScriptCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:typescript
        {--check : Fail when the file on disk differs, instead of writing it}
        {--group=* : Write or check only these discovery groups\' files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write the typed action definitions for @agentic-actions/client';

    /**
     * Execute the console command.
     */
    public function handle(Emitter $emitter, Filesystem $files, Groups $groups): int
    {
        if ($this->laravel instanceof CachesRoutes && $this->laravel->routesAreCached()) {
            $this->components->error('Routes are cached, so new actions are invisible. Run php artisan route:clear first.');

            return self::FAILURE;
        }

        /** @var list<string> $only */
        $only = array_values(array_map(strval(...), (array) $this->option('group')));

        if (($unknown = Groups::unknown($only)) !== []) {
            $this->components->error('There is no discovery group ['.implode(', ', $unknown).'].');

            return self::FAILURE;
        }

        if (! Groups::grouped()) {
            return $this->emit($emitter->path(), $emitter->render(), $files) ? self::SUCCESS : self::FAILURE;
        }

        try {
            $scans = $groups->scans();
            $named = Groups::named($only);
        } catch (DuplicateActionName|MisconfiguredDiscovery $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $passed = true;
        $claimed = [];

        foreach ($scans as $scan) {
            foreach ($scan->actions as $entry) {
                $claimed[] = $entry->class;
            }
        }

        if ($scans === []) {
            $this->components->error('No discovery group is installed: none of the paths of any group in discovery.groups exists.');

            return self::FAILURE;
        }

        // Every group's exports share one name space in the frontend that imports them, so a name two groups export
        // is refused before any file is written, as one file's two exports are.
        $emitter->verify($claimed);

        foreach ($named as $group) {
            if (! isset($scans[$group->name])) {
                // A required group with a missing path, or one --group names, fails instead of passing on nothing.
                if (! $group->optional || $only !== []) {
                    $this->components->error("The {$group->name} group is not installed: none of its paths exists (".implode(', ', $group->paths).').'.($group->optional ? '' : " Fix the path, or set 'optional' => true on a group a deployment may leave out."));

                    $passed = false;
                } else {
                    $this->components->info("Skipped the {$group->name} group: none of its paths exists.");
                }

                continue;
            }

            if (($path = $group->typescriptPath()) === null) {
                continue;
            }

            $classes = array_values(array_map(fn ($entry): string => $entry->class, $scans[$group->name]->actions));

            $passed = $this->emit($path, $emitter->render($classes), $files) && $passed;
        }

        if (($unclaimed = array_values(array_diff($emitter->classes(), $claimed))) !== []) {
            $this->components->warn('No discovery group finds '.implode(', ', $unclaimed).', so no file exports '.(count($unclaimed) === 1 ? 'its route' : 'their routes').'. List each one under a group\'s classes.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Write one file, or with --check compare it. Returns false when --check finds it stale.
     */
    private function emit(string $path, string $contents, Filesystem $files): bool
    {
        $shown = $this->shown($path);

        if ($this->option('check')) {
            if (! $files->isFile($path) || $files->get($path) !== $contents) {
                $this->components->error("{$shown} is stale: run php artisan actions:typescript");

                return false;
            }

            $this->components->info("{$shown} is current.");

            return true;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->info("Wrote {$shown}.");

        return true;
    }

    /**
     * The path relative to the app's base path when it lies inside it, else as is.
     */
    private function shown(string $path): string
    {
        $base = rtrim($this->laravel->basePath(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
