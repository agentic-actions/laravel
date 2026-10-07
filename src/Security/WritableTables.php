<?php

namespace AgenticActions\Security;

use Illuminate\Database\Connection;

/**
 * The tables a Read may write on one connection: the database-backed cache, session and queue tables the app's own
 * config names, plus reads.writable_tables.
 *
 * @internal
 */
final class WritableTables
{
    /**
     * The tables a Read may write on this connection: derived from the app's config, plus reads.writable_tables.
     *
     * @return list<string>
     */
    public function for(Connection $connection): array
    {
        // The configured name, never getName(), which carries a "::direct" suffix for a direct connection.
        $name = $connection->getConfig('name');
        $default = config('database.default');
        $tables = [];

        $on = fn (mixed $connection): bool => ($connection ?? $default) === $name;

        foreach ((array) config('cache.stores', []) as $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }

            if ($on($store['connection'] ?? null)) {
                $tables[] = $store['table'] ?? 'cache';
            }

            if ($on($store['lock_connection'] ?? $store['connection'] ?? null)) {
                $tables[] = $store['lock_table'] ?? 'cache_locks';
            }
        }

        if (config('session.driver') === 'database' && $on(config('session.connection'))) {
            $tables[] = config('session.table') ?? 'sessions';
        }

        foreach ((array) config('queue.connections', []) as $queue) {
            if (is_array($queue) && ($queue['driver'] ?? null) === 'database' && $on($queue['connection'] ?? null)) {
                $tables[] = $queue['table'] ?? 'jobs';
            }
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) && $on(config('queue.failed.database'))) {
            $tables[] = config('queue.failed.table') ?? 'failed_jobs';
        }

        if ((config('queue.batching.driver') ?? 'database') === 'database' && config('queue.batching.table') !== null && $on(config('queue.batching.database'))) {
            $tables[] = config('queue.batching.table');
        }

        $prefix = (string) $connection->getTablePrefix();

        $tables = array_map(fn (mixed $table): string => self::prefixed((string) $table, $prefix), $tables);

        foreach ((array) config('agentic-actions.reads.writable_tables', []) as $table) {
            $tables[] = self::prefixed((string) $table, $prefix);
        }

        return array_values(array_unique($tables));
    }

    /**
     * Tables an action names, such as its $initializes, as the connection's grammar writes them, lower-cased.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function named(array $tables, Connection $connection): array
    {
        $prefix = (string) $connection->getTablePrefix();

        return array_values(array_unique(array_map(fn (string $table): string => self::prefixed($table, $prefix), $tables)));
    }

    /**
     * A table name as the connection's grammar writes it, lower-cased: the prefix goes on the last segment, so a
     * schema-qualified "public.cache" becomes "public.app_cache", which is how SqlStatement reads a qualified name.
     */
    private static function prefixed(string $table, string $prefix): string
    {
        $dot = strrpos($table, '.');

        return strtolower($dot === false ? $prefix.$table : substr($table, 0, $dot + 1).$prefix.substr($table, $dot + 1));
    }
}
