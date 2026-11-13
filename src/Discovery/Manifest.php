<?php

namespace AgenticActions\Discovery;

use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Exposure\Entry;
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
     * The manifest of a scan. With discovery.groups it also records Groups::fingerprint(), so a manifest written for
     * other groups, or while a module that is gone now was installed, is stale; without them it is as it always was.
     *
     * @return array<string, mixed>
     *
     * @throws MisconfiguredDiscovery
     */
    public static function build(Scan $scan): array
    {
        $manifest = [
            'version' => ActionRegistry::VERSION,
            'actions' => array_map(fn (Entry $entry): array => $entry->toManifest(), $scan->actions),
            'agents' => $scan->recordedAgents(),
        ];

        if (($fingerprint = Groups::fingerprint()) !== null) {
            $manifest['groups'] = $fingerprint;
        }

        return $manifest;
    }

    /**
     * The manifest array when it exists, is at least as new as the route cache, has this VERSION and was written for
     * the discovery groups installed now; else null.
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

        // A manifest written for other discovery groups would nominate a module that is no longer installed, whose
        // classes may still autoload. A misconfigured discovery.groups is the registry's to report.
        try {
            $fingerprint = Groups::fingerprint();
        } catch (MisconfiguredDiscovery) {
            return null;
        }

        if (($manifest['groups'] ?? null) !== $fingerprint) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }
}
