<?php

namespace AgenticActions\Discovery\Console;

use AgenticActions\Discovery\ManifestWriter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Writes the manifest production reads. It throws on a misconfigured action instead of returning a failure code,
 * because optimize ignores its tasks' exit codes but rethrows their exceptions.
 *
 * @internal
 */
#[AsCommand(name: 'actions:cache')]
final class CacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache the discovered actions for production';

    /**
     * Execute the console command.
     */
    public function handle(ManifestWriter $writer): int
    {
        $manifest = require $writer->write();

        $count = is_array($manifest) && is_array($manifest['actions'] ?? null) ? count($manifest['actions']) : 0;

        $this->components->info("Actions cached: {$count}.");

        return self::SUCCESS;
    }
}
