<?php

namespace AgenticActions\Discovery;

use AgenticActions\Exceptions\DuplicateActionName;
use AgenticActions\Exceptions\MisconfiguredDiscovery;
use AgenticActions\Support\Paths;

/**
 * The discovery groups: discovery.groups when the app sets it, else one implicit group read from discovery.paths,
 * discovery.classes, snapshot and typescript.path. Every scan the package makes goes through here, so an app that
 * configures no group scans exactly what it did before, and a group whose roots are missing adds nothing anywhere.
 *
 * A configured group's paths and outputs stay inside the app: an absolute path outside the base path, a ".." segment
 * or a root whose real path leaves the base path is refused before anything is scanned or written.
 *
 * @internal
 */
final class Groups
{
    /**
     * The keys a configured group may set.
     */
    private const KEYS = ['paths', 'classes', 'typescript', 'snapshot', 'optional'];

    /**
     * The extension each output must carry, so an output never overwrites a PHP source or any other file.
     */
    private const EXTENSIONS = ['snapshot' => '.json', 'typescript' => '.ts'];

    /**
     * Create the groups service.
     */
    public function __construct(private readonly Scanner $scanner) {}

    /**
     * Whether the app configures discovery.groups.
     */
    public static function grouped(): bool
    {
        return (array) config('agentic-actions.discovery.groups', []) !== [];
    }

    /**
     * Every group, in configured order, validated.
     *
     * @return list<Group>
     *
     * @throws MisconfiguredDiscovery
     */
    public static function configured(): array
    {
        if (! self::grouped()) {
            return [new Group(
                Group::DEFAULT,
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.paths', []))),
                array_values(array_map(strval(...), (array) config('agentic-actions.discovery.classes', []))),
                (string) config('agentic-actions.typescript.path', 'resources/js/agentic/actions.ts'),
                (string) config('agentic-actions.snapshot', 'actions.exposure.json'),
                implicit: true,
            )];
        }

        $groups = [];
        $outputs = [];

        foreach ((array) config('agentic-actions.discovery.groups') as $name => $definition) {
            $group = self::group($name, $definition);

            foreach (['snapshot' => $group->snapshotPath(), 'typescript' => $group->typescriptPath()] as $key => $path) {
                if ($path === null) {
                    continue;
                }

                // a.json, ./a.json and A.json are one file on a case-insensitive disk, so they compare as one.
                $file = self::normalized($path);

                if (isset($outputs[$file])) {
                    throw MisconfiguredDiscovery::in($group->name, "{$key} [{$path}] is already written by {$outputs[$file]}. Give each group its own files.");
                }

                $outputs[$file] = "discovery.groups.{$group->name}.{$key}";
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * The groups whose code is here.
     *
     * @return list<Group>
     *
     * @throws MisconfiguredDiscovery
     */
    public static function installed(): array
    {
        return array_values(array_filter(self::configured(), fn (Group $group): bool => $group->installed()));
    }

    /**
     * The groups a deployment may not leave out whose code is not here: a required group none of whose paths exists,
     * such as a core path with a typo. The checks fail on each one rather than pass while checking nothing.
     *
     * @return list<Group>
     *
     * @throws MisconfiguredDiscovery
     */
    public static function missing(): array
    {
        return array_values(array_filter(self::configured(), fn (Group $group): bool => ! $group->optional && ! $group->installed()));
    }

    /**
     * What the manifest records of discovery.groups, so a manifest written for another set of groups, or before a
     * module was added or removed, reads as stale: a hash of every configured group and of which ones are installed.
     * Null without discovery.groups, so that manifest stays as it was.
     *
     * @throws MisconfiguredDiscovery
     */
    public static function fingerprint(): ?string
    {
        if (! self::grouped()) {
            return null;
        }

        $groups = array_map(fn (Group $group): array => [
            'name' => $group->name,
            'paths' => $group->paths,
            'classes' => $group->classes,
            'snapshot' => $group->snapshot,
            'typescript' => $group->typescript,
            'optional' => $group->optional,
            'installed' => $group->installed(),
        ], self::configured());

        return hash('sha256', json_encode($groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The configured groups of these names, in configured order; every group for an empty list.
     *
     * @param  list<string>  $names
     * @return list<Group>
     *
     * @throws MisconfiguredDiscovery when a name is not configured
     */
    public static function named(array $names): array
    {
        $groups = self::configured();

        if ($names === []) {
            return $groups;
        }

        $known = array_map(fn (Group $group): string => $group->name, $groups);

        foreach ($names as $name) {
            if (! in_array($name, $known, true)) {
                throw new MisconfiguredDiscovery("There is no discovery group [{$name}]. The groups are: ".implode(', ', $known).'.');
            }
        }

        return array_values(array_filter($groups, fn (Group $group): bool => in_array($group->name, $names, true)));
    }

    /**
     * The names that are no configured group. A misconfigured discovery.groups lists none, since the Discovery row
     * reports it.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function unknown(array $names): array
    {
        try {
            $known = array_map(fn (Group $group): string => $group->name, self::configured());
        } catch (MisconfiguredDiscovery) {
            return [];
        }

        return array_values(array_diff($names, $known));
    }

    /**
     * One scan of every installed group together: what the registry, the manifest and every check read. Without
     * discovery.groups it is the scan of discovery.paths and discovery.classes, as before.
     *
     * @throws MisconfiguredDiscovery
     * @throws DuplicateActionName
     */
    public function scan(): Scan
    {
        $paths = [];
        $classes = [];

        if (self::grouped()) {
            $this->refuseOverlap(self::installed());
        }

        foreach (self::installed() as $group) {
            array_push($paths, ...$group->paths);
            array_push($classes, ...$group->classes);
        }

        return $this->scanner->scan($paths, $classes);
    }

    /**
     * Each installed group's own scan, keyed by group name. A class two groups both find is refused, since its
     * snapshot row and TypeScript export would belong to two owners.
     *
     * @return array<string, Scan>
     *
     * @throws MisconfiguredDiscovery
     * @throws DuplicateActionName
     */
    public function scans(): array
    {
        $installed = self::installed();
        $scans = [];

        $this->refuseOverlap($installed);

        foreach ($installed as $group) {
            $scans[$group->name] = $this->scanner->scan($group->paths, $group->classes);
        }

        return $scans;
    }

    /**
     * Refuse a class that two installed groups both find, under their paths or in their classes. It reads each
     * group's sources as text and loads nothing, so the registry runs it on every scan as cheaply as the checks do.
     *
     * @param  list<Group>  $groups
     *
     * @throws MisconfiguredDiscovery
     */
    private function refuseOverlap(array $groups): void
    {
        $owners = [];

        foreach ($groups as $group) {
            foreach ($this->scanner->candidates($group->paths, $group->classes) as $class) {
                if (isset($owners[$class]) && $owners[$class] !== $group->name) {
                    throw MisconfiguredDiscovery::in($group->name, "{$class} is also found by discovery.groups.{$owners[$class]}. A class belongs to one group: narrow the paths so they do not overlap.");
                }

                $owners[$class] = $group->name;
            }
        }
    }

    /**
     * Build and validate one configured group.
     *
     * @throws MisconfiguredDiscovery
     */
    private static function group(int|string $name, mixed $definition): Group
    {
        if (! is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/', $name) !== 1) {
            throw new MisconfiguredDiscovery("discovery.groups: [{$name}] is not a group name. Key each group by a name of letters, digits, \"-\" and \"_\", such as core.");
        }

        if (! is_array($definition)) {
            throw MisconfiguredDiscovery::in($name, 'a group is an array with paths and snapshot, and optionally classes and typescript.');
        }

        if (($unknown = array_diff(array_map(strval(...), array_keys($definition)), self::KEYS)) !== []) {
            throw MisconfiguredDiscovery::in($name, 'unknown key ['.implode(', ', $unknown).']. A group takes '.implode(', ', self::KEYS).'.');
        }

        $paths = $definition['paths'] ?? null;

        if (! is_array($paths) || $paths === [] || ! array_is_list($paths)) {
            throw MisconfiguredDiscovery::in($name, 'paths is a non-empty list of directories, relative to the base path.');
        }

        foreach ($paths as $path) {
            self::inside($name, 'paths', $path, glob: true);
        }

        $classes = $definition['classes'] ?? [];

        if (! is_array($classes) || ! array_is_list($classes) || array_filter($classes, fn (mixed $class): bool => ! is_string($class) || $class === '') !== []) {
            throw MisconfiguredDiscovery::in($name, 'classes is a list of class names.');
        }

        if (! array_key_exists('snapshot', $definition)) {
            throw MisconfiguredDiscovery::in($name, 'snapshot is missing. Every group commits its own exposure snapshot, such as modules/Billing/actions.exposure.json.');
        }

        $typescript = $definition['typescript'] ?? null;
        $optional = $definition['optional'] ?? false;

        if (! is_bool($optional)) {
            throw MisconfiguredDiscovery::in($name, 'optional is true or false.');
        }

        /** @var list<string> $paths */
        /** @var list<class-string> $classes */
        return new Group(
            $name,
            $paths,
            $classes,
            $typescript === null ? null : self::inside($name, 'typescript', $typescript, glob: false),
            self::inside($name, 'snapshot', $definition['snapshot'], glob: false),
            optional: $optional,
        );
    }

    /**
     * A configured path, refused unless it stays inside the base path: no ".." segment, no absolute path outside the
     * base path, and, for a root that exists, no real path outside it either (a symbolic link out of the app). An
     * output also carries its extension, and its nearest existing directory, and the file itself when it exists,
     * must really lie inside the base path, so no symbolic link carries a write out of the app.
     *
     * @throws MisconfiguredDiscovery
     */
    private static function inside(string $group, string $key, mixed $path, bool $glob): string
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            throw MisconfiguredDiscovery::in($group, "{$key} must be a path, relative to the base path.");
        }

        if (in_array('..', preg_split('#[/\\\\]+#', $path) ?: [], true)) {
            throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] steps out with \"..\". A group's paths stay inside the base path.");
        }

        if (! $glob && strpbrk($path, '*?[{') !== false) {
            throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] is a pattern. Name one file.");
        }

        $base = rtrim(base_path(), '/\\');
        $resolved = Paths::resolve($path);

        if (! self::within($resolved, [$base, realpath($base) ?: $base])) {
            throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] is outside the base path [{$base}].");
        }

        $real = realpath($base) ?: $base;

        if (! $glob) {
            $extension = self::EXTENSIONS[$key];

            if (! str_ends_with(strtolower($path), $extension)) {
                throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] is not a {$extension} file.");
            }

            $existing = $resolved;

            while (! file_exists($existing) && ! is_link($existing) && dirname($existing) !== $existing) {
                $existing = dirname($existing);
            }

            $target = realpath($existing);

            if ($target === false || ! self::within($target, [$real])) {
                throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] resolves to [".($target ?: $existing)."], outside the base path [{$base}].");
            }

            if (is_dir($resolved)) {
                throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] is a directory. Name one file.");
            }
        }

        if ($glob) {
            foreach (glob($resolved, GLOB_ONLYDIR) ?: [] as $directory) {
                if (! self::within(realpath($directory) ?: $directory, [$real])) {
                    throw MisconfiguredDiscovery::in($group, "{$key} [{$path}] resolves to [".(realpath($directory) ?: $directory)."], outside the base path [{$base}].");
                }
            }
        }

        return $path;
    }

    /**
     * A resolved output path in the form two groups' outputs compare by: one separator, no "." segment, lower case.
     */
    private static function normalized(string $path): string
    {
        $segments = array_filter(explode('/', str_replace('\\', '/', $path)), fn (string $segment): bool => $segment !== '' && $segment !== '.');

        return strtolower((str_starts_with($path, '/') ? '/' : '').implode('/', $segments));
    }

    /**
     * Whether a path is one of the bases or lies under one.
     *
     * @param  list<string>  $bases
     */
    private static function within(string $path, array $bases): bool
    {
        foreach ($bases as $base) {
            if ($path === $base || str_starts_with($path, $base.'/') || str_starts_with($path, $base.'\\')) {
                return true;
            }
        }

        return false;
    }
}
