<?php

namespace AgenticActions\Discovery\Console;

use AgenticActions\Discovery\ManifestWriter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes the manifest, so the next production boot scans until actions:cache runs again.
 *
 * @internal
 */
#[AsCommand(name: 'actions:clear')]
final class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'actions:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove the cached actions manifest';

    /**
     * Execute the console command.
     */
    public function handle(ManifestWriter $writer): int
    {
        $writer->clear();

        $this->components->info('Actions manifest cleared.');

        return self::SUCCESS;
    }
}
