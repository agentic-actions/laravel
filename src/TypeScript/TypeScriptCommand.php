<?php

namespace AgenticActions\TypeScript;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Writes the typed action definitions the npm client imports, or checks that the committed file is current.
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
        {--check : Fail when the file on disk differs, instead of writing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write the typed action definitions for @agentic-actions/client';

    /**
     * Execute the console command.
     */
    public function handle(Emitter $emitter, Filesystem $files): int
    {
        if ($this->laravel instanceof CachesRoutes && $this->laravel->routesAreCached()) {
            $this->components->error('Routes are cached, so new actions are invisible. Run php artisan route:clear first.');

            return self::FAILURE;
        }

        $path = $emitter->path();
        $shown = $this->shown($path);
        $contents = $emitter->render();

        if ($this->option('check')) {
            if (! $files->isFile($path) || $files->get($path) !== $contents) {
                $this->components->error("{$shown} is stale: run php artisan actions:typescript");

                return self::FAILURE;
            }

            $this->components->info("{$shown} is current.");

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->info("Wrote {$shown}.");

        return self::SUCCESS;
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
