<?php

namespace AgenticActions\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Throwable;

/**
 * The cached action list that production reads instead of scanning. It only nominates: every call re-reads the class.
 *
 * @internal
 */
final class Manifest
{
    /**
     * bootstrap/cache/agentic-actions.php.
     */
    public static function path(Application $app): string
    {
        return $app->bootstrapPath('cache/agentic-actions.php');
    }

    /**
     * The manifest array when it exists, is at least as new as the route cache, and has this VERSION; else null.
     *
     * @return array<string, mixed>|null
     */
    public static function readFresh(Application $app): ?array
    {
        $path = self::path($app);

        if (! is_file($path)) {
            return null;
        }

        // A manifest older than the route cache belongs to a previous release.
        $routes = $app instanceof CachesRoutes ? $app->getCachedRoutesPath() : null;

        if ($routes !== null && is_file($routes) && filemtime($path) < filemtime($routes)) {
            return null;
        }

        try {
            $manifest = require $path;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== ActionRegistry::VERSION || ! is_array($manifest['actions'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
