<?php

namespace AgenticActions\Discovery;

use AgenticActions\Support\Paths;

/**
 * One owner's share of discovery, such as the app's core or one optional module: the roots it scans, and the
 * TypeScript file and exposure snapshot it owns. Without discovery.groups the app has one implicit group, "default",
 * built from discovery.paths, discovery.classes, snapshot and typescript.path exactly as before.
 *
 * @internal
 */
final class Group
{
    /**
     * The name of the implicit group.
     */
    public const DEFAULT = 'default';

    /**
     * Create a group. Groups::configured() validates every field first.
     *
     * @param  list<string>  $paths  as configured
     * @param  list<class-string>  $classes
     * @param  string|null  $typescript  as configured; null writes no TypeScript file for the group
     * @param  string  $snapshot  as configured
     * @param  bool  $implicit  the group built from the top-level keys, which is always installed
     * @param  bool  $optional  whether a deployment may leave the group out; a required group whose paths are all
     *                          missing fails actions:check and actions:typescript instead of being skipped
     */
    public function __construct(
        public readonly string $name,
        public readonly array $paths,
        public readonly array $classes,
        public readonly ?string $typescript,
        public readonly string $snapshot,
        public readonly bool $implicit = false,
        public readonly bool $optional = false,
    ) {}

    /**
     * Whether the group's code is here. The implicit group always is; a configured one is when one of its paths names
     * a directory, so removing a module's folder removes its actions, its routes, its tools and its outputs. A group
     * that is not installed only ever adds nothing; whether that is allowed is the checks' question (see $optional).
     */
    public function installed(): bool
    {
        return $this->implicit || self::exists($this->paths);
    }

    /**
     * Whether one of these configured paths names a directory.
     *
     * @param  list<string>  $paths
     */
    public static function exists(array $paths): bool
    {
        foreach ($paths as $path) {
            if ((glob(Paths::resolve($path), GLOB_ONLYDIR) ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The snapshot's resolved path.
     */
    public function snapshotPath(): string
    {
        return Paths::resolve($this->snapshot);
    }

    /**
     * The TypeScript file's resolved path, or null when the group writes none.
     */
    public function typescriptPath(): ?string
    {
        return $this->typescript === null ? null : Paths::resolve($this->typescript);
    }
}
