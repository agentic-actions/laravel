<?php

namespace AgenticActions\Discovery;

use AgenticActions\Action;
use AgenticActions\Exceptions\MisconfiguredExposure;
use AgenticActions\Exceptions\StaleActionManifest;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Surface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The actions this process knows by name, from the manifest or a scan. It only nominates: every gate re-reads the
 * class through ClassExposure, so a stale manifest can hide an action but never widen one.
 *
 * @internal
 */
final class ActionRegistry
{
    /**
     * The manifest format this release reads and writes.
     */
    public const VERSION = 5;

    /**
     * Create a registry.
     *
     * @param  array<string, Entry>  $actions  keyed by name, sorted
     * @param  array<class-string, list<string>>  $agents
     */
    private function __construct(
        private readonly array $actions,
        private readonly array $agents,
        private readonly bool $fromManifest,
    ) {}

    /**
     * Load the manifest or scan.
     */
    public static function load(Application $app): self
    {
        // The console always scans: optimize runs route:cache before any package's optimizes() command, so a route
        // file reading last release's manifest would compile a stale action set into the route cache. Local always
        // scans, so a new action appears on the next request. Nothing here writes the snapshot.
        if ($app->runningInConsole() || $app->environment('local')) {
            return self::fromScan($app);
        }

        // A manifest older than the route cache belongs to a previous release. Every gate re-reads the class, so a
        // stale manifest can only hide a new action, never widen an old one.
        if (($manifest = Manifest::readFresh($app)) !== null) {
            return self::fromCachedManifest($manifest);
        }

        // FPM keeps nothing between requests, so "once per worker" would be once per request: once an hour instead.
        if (Cache::add('agentic-actions:manifest-missing', true, 3600)) {
            report(new StaleActionManifest('The actions manifest is missing or older than the route cache, so this process scanned for actions: run php artisan optimize, or actions:cache, on deploy.'));
        }

        return self::fromScan($app);
    }

    /**
     * Every candidate, keyed by name.
     *
     * @return array<string, Entry>
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * A candidate by name or class.
     */
    public function find(string $nameOrClass): ?Entry
    {
        if (isset($this->actions[$nameOrClass])) {
            $entry = $this->actions[$nameOrClass];

            // A manifest row whose class now declares another name no longer answers to this one, so a stale manifest
            // never runs a renamed class under its old name. A class that is gone stays nominated: the Runner reads it
            // as not found.
            return $this->fromManifest && self::renamed($entry, $nameOrClass) ? null : $entry;
        }

        $class = ltrim($nameOrClass, '\\');

        foreach ($this->actions as $entry) {
            if ($entry->class === $class) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The candidates the manifest or scan nominates for a surface.
     *
     * @return list<Entry>
     */
    public function on(Surface $surface): array
    {
        return array_values(array_filter($this->actions, fn (Entry $entry): bool => $entry->allows($surface)));
    }

    /**
     * The agent classes that carry #[UseToolset], with their toolsets.
     *
     * @return array<class-string, list<string>>
     */
    public function agents(): array
    {
        return $this->agents;
    }

    /**
     * Whether the class a manifest row names exists as an action and now declares a name other than the row's.
     */
    private static function renamed(Entry $entry, string $name): bool
    {
        try {
            return is_subclass_of($entry->class, Action::class) && ClassExposure::of($entry->class)->name !== $name;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Scan the configured paths and classes, then apply the table of where an exposure error lands.
     */
    private static function fromScan(Application $app): self
    {
        /** @var list<string> $paths */
        $paths = array_values((array) config('agentic-actions.discovery.paths', []));

        /** @var list<class-string> $classes */
        $classes = array_values((array) config('agentic-actions.discovery.classes', []));

        $scan = $app->make(Scanner::class)->scan($paths, $classes);

        if (($errors = $scan->errors()) !== []) {
            self::misconfigured($app, $errors);
        }

        return new self($scan->actions, $scan->agents, false);
    }

    /**
     * Rebuild the registry from a manifest array.
     *
     * @param  array<string, mixed>  $manifest
     */
    private static function fromCachedManifest(array $manifest): self
    {
        $actions = [];

        foreach ((array) $manifest['actions'] as $row) {
            if (is_array($row) && is_string($row['class'] ?? null) && is_string($row['name'] ?? null)) {
                $entry = Entry::fromManifest($row);

                $actions[$entry->name] = $entry;
            }
        }

        ksort($actions, SORT_STRING);

        $agents = [];

        foreach ((array) ($manifest['agents'] ?? []) as $class => $toolsets) {
            /** @var class-string $class */
            $agents[$class] = array_values(array_map(strval(...), (array) $toolsets));
        }

        return new self($actions, $agents, true);
    }

    /**
     * Where a scan's exposure errors land. The first matching row wins: a test run throws, any other console process
     * records them (actions:cache throws), a local web request throws, and production reports them once an hour
     * while the refused surfaces stay closed.
     *
     * @param  list<string>  $errors
     *
     * @throws MisconfiguredExposure
     */
    private static function misconfigured(Application $app, array $errors): void
    {
        $exception = MisconfiguredExposure::withErrors($errors);

        // PHPUnit is a console process too, so the test-run row comes first.
        if ($app->runningUnitTests()) {
            throw $exception;
        }

        if ($app->runningInConsole()) {
            return;
        }

        if ($app->environment('local')) {
            throw $exception;
        }

        if (Cache::add('agentic-actions:misconfigured-exposure', true, 3600)) {
            report($exception);
        }
    }
}
