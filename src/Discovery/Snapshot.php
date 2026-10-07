<?php

namespace AgenticActions\Discovery;

use AgenticActions\Exposure\Entry;
use AgenticActions\Support\Paths;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use stdClass;

/**
 * actions.exposure.json: the committed, reviewable form of the allowlist. Nothing writes it on boot; only
 * php artisan actions:check --update does, so a widening is always a reviewed diff.
 *
 * @internal
 */
final class Snapshot
{
    /**
     * The snapshot format.
     */
    private const VERSION = 1;

    /**
     * The snapshot array for a registry or a fresh scan (the console commands pass a Scan).
     *
     * @return array{version: int, actions: array<string, array<string, mixed>>, agents: array<string, list<string>|array{use: list<string>, defer: list<string>}>}
     */
    public static function build(ActionRegistry|Scan $source): array
    {
        [$actions, $agents] = $source instanceof Scan
            ? [$source->actions, $source->recordedAgents()]
            : [$source->all(), $source->agents()];

        return [
            'version' => self::VERSION,
            'actions' => array_map(fn (Entry $entry): array => $entry->toSnapshot(), $actions),
            'agents' => $agents,
        ];
    }

    /**
     * The configured path, resolved.
     */
    public static function path(): string
    {
        return Paths::resolve((string) config('agentic-actions.snapshot', 'actions.exposure.json'));
    }

    /**
     * The committed snapshot, or null when the file is missing or unreadable.
     *
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        $path = self::path();

        if (! is_file($path) || ($text = @file_get_contents($path)) === false) {
            return null;
        }

        try {
            $snapshot = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Write through a temporary file and rename(), creating the configured file's directory first.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function write(array $snapshot): void
    {
        $files = app(Filesystem::class);
        $path = self::path();

        $files->ensureDirectoryExists(dirname($path));
        $files->replace($path, self::encode($snapshot), 0666 & ~umask());
    }

    /**
     * The canonical text: keys sorted, pretty-printed, unescaped slashes and unicode, trailing newline.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function encode(array $snapshot): string
    {
        $snapshot = self::sorted($snapshot);

        // The snapshot itself and its "actions" and "agents" are maps, so each encodes as {} when it is empty.
        foreach (['actions', 'agents'] as $map) {
            if (($snapshot[$map] ?? null) === []) {
                $snapshot[$map] = new stdClass;
            }
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($snapshot === [] ? new stdClass : $snapshot, $flags)."\n";
    }

    /**
     * Sort every associative array by key, at every depth, and keep lists in order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
