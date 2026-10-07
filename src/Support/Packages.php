<?php

namespace AgenticActions\Support;

use Composer\InstalledVersions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Passport\Passport;

/**
 * What Composer says is installed, with overrides for tests and the workbench.
 *
 * @internal
 */
final class Packages
{
    /**
     * Create a package reader.
     *
     * @param  array<string, PackageStatus|string>  $overrides  a status or a pretty version per package, for tests and the workbench
     */
    public function __construct(private readonly array $overrides = []) {}

    /**
     * Whether a package is missing, installed only as a dev requirement, or installed for production.
     */
    public function status(string $package): PackageStatus
    {
        $override = $this->overrides[$package] ?? null;

        if ($override instanceof PackageStatus) {
            return $override;
        }

        if (! InstalledVersions::isInstalled($package)) {
            return PackageStatus::Missing;
        }

        if (! InstalledVersions::isInstalled($package, false)) {
            return PackageStatus::DevOnly;
        }

        return PackageStatus::Installed;
    }

    /**
     * laravel/ai's status, downgraded to Missing when its Tool contract cannot be loaded.
     */
    public function laravelAi(): PackageStatus
    {
        $status = $this->status('laravel/ai');

        return interface_exists(Tool::class) ? $status : PackageStatus::Missing;
    }

    /**
     * Whether the installed laravel/ai takes an agent's tool-search group whatever its provider: a provider that
     * searches tools searches the group, and any other receives its tools as ordinary tools. False without laravel/ai.
     *
     * @upstream Deferred toolsets are sent as ordinary tools while laravel/ai is older than 1.1.
     */
    public function toolSearch(): bool
    {
        return class_exists(TextGenerationLoop::class) && ! method_exists(TextGenerationLoop::class, 'ensureToolSearchIsApplicable');
    }

    /**
     * laravel/passport's status, downgraded to Missing when its Passport class cannot be loaded.
     */
    public function passport(): PackageStatus
    {
        return class_exists(Passport::class) ? $this->status('laravel/passport') : PackageStatus::Missing;
    }

    /**
     * The installed pretty version with one leading "v" removed ("0.1.0" for a v0.1.0 tag, "dev-main"), or null.
     */
    public function version(string $package): ?string
    {
        $override = $this->overrides[$package] ?? null;

        $version = match (true) {
            is_string($override) => $override,
            InstalledVersions::isInstalled($package) => InstalledVersions::getPrettyVersion($package),
            default => null,
        };

        return $version === null ? null : (string) preg_replace('/^v(?=\d)/', '', $version);
    }
}
