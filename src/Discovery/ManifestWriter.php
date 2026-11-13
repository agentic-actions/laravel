<?php

namespace AgenticActions\Discovery;

use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Exceptions\MisconfiguredExposure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes and deletes the manifest. It writes nothing while any action is misconfigured, so a deploy that runs
 * php artisan optimize fails instead of shipping a refused surface.
 *
 * @internal
 */
final class ManifestWriter
{
    /**
     * Create the writer.
     */
    public function __construct(
        private readonly Application $app,
        private readonly Groups $groups,
        private readonly Filesystem $files,
    ) {}

    /**
     * Scan every installed group, refuse on errors, write atomically. Returns the path written.
     *
     * @throws MisconfiguredExposure
     * @throws MisconfiguredDiscovery
     */
    public function write(): string
    {
        $scan = $this->groups->scan();

        if (($errors = $scan->errors()) !== []) {
            throw MisconfiguredExposure::withErrors($errors);
        }

        $manifest = Manifest::build($scan);

        $path = Manifest::path($this->app);

        // A temporary file beside the target, then rename(): a reader never sees half a manifest.
        $this->files->replace($path, '<?php return '.var_export($manifest, true).';'.PHP_EOL, 0666 & ~umask());

        return $path;
    }

    /**
     * Delete the manifest, if there is one.
     */
    public function clear(): void
    {
        $path = Manifest::path($this->app);

        if (is_file($path)) {
            $this->files->delete($path);
        }
    }
}
