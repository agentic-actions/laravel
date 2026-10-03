<?php

use AgenticActions\Security\WritableTables;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;

/**
 * A connection that never connects, with a configured name and a table prefix.
 */
function namedConnection(string $name, string $prefix = ''): Connection
{
    return new SQLiteConnection(fn () => throw new LogicException('Never connects.'), ':memory:', $prefix, ['name' => $name]);
}

beforeEach(function () {
    config([
        'cache.stores' => [
            'database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => null],
            'array' => ['driver' => 'array'],
        ],
        'session.driver' => 'database',
        'session.connection' => null,
        'session.table' => 'sessions',
        'queue.connections' => [
            'database' => ['driver' => 'database', 'connection' => null, 'table' => 'jobs'],
            'sync' => ['driver' => 'sync'],
        ],
        'queue.failed' => ['driver' => 'database-uuids', 'database' => config('database.default'), 'table' => 'failed_jobs'],
        'queue.batching' => ['database' => config('database.default'), 'table' => 'job_batches'],
        'agentic-actions.reads.writable_tables' => [],
    ]);
});

it('derives the cache, session and queue tables of a fresh app', function () {
    expect((new WritableTables)->for(namedConnection(config('database.default'))))
        ->toBe(['cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs', 'job_batches']);
});

it('applies the connection\'s table prefix, lower-cased', function () {
    expect((new WritableTables)->for(namedConnection(config('database.default'), 'APP_')))
        ->toBe(['app_cache', 'app_cache_locks', 'app_sessions', 'app_jobs', 'app_failed_jobs', 'app_job_batches']);
});

it('reads renamed tables and lock tables from config', function () {
    config([
        'cache.stores.database.table' => 'app_cache',
        'cache.stores.database.lock_table' => 'app_locks',
        'session.table' => 'app_sessions',
    ]);

    expect((new WritableTables)->for(namedConnection(config('database.default'))))
        ->toContain('app_cache', 'app_locks', 'app_sessions')
        ->not->toContain('cache')
        ->not->toContain('cache_locks')
        ->not->toContain('sessions');
});

it('leaves out stores on another connection', function () {
    config([
        'cache.stores.database.connection' => 'cache-db',
        'cache.stores.database.lock_connection' => config('database.default'),
        'session.connection' => 'sessions-db',
        'queue.connections.database.connection' => 'queue-db',
        'queue.failed.database' => 'queue-db',
        'queue.batching.database' => 'queue-db',
    ]);

    expect((new WritableTables)->for(namedConnection(config('database.default'))))->toBe(['cache_locks'])
        ->and((new WritableTables)->for(namedConnection('cache-db')))->toBe(['cache'])
        ->and((new WritableTables)->for(namedConnection('queue-db')))->toBe(['jobs', 'failed_jobs', 'job_batches']);
});

it('leaves out stores that are not database-backed', function () {
    config([
        'cache.stores.database.driver' => 'redis',
        'session.driver' => 'file',
        'queue.connections.database.driver' => 'redis',
        'queue.failed.driver' => 'file',
        'queue.batching.driver' => 'dynamodb',
    ]);

    expect((new WritableTables)->for(namedConnection(config('database.default'))))->toBe([]);
});

it('appends reads.writable_tables on every connection', function () {
    config(['agentic-actions.reads.writable_tables' => ['Audit_Log']]);

    expect((new WritableTables)->for(namedConnection(config('database.default'), 'app_')))->toContain('app_audit_log')
        ->and((new WritableTables)->for(namedConnection('other')))->toBe(['audit_log']);
});

it('matches stores by the configured name, never getName()', function () {
    $connection = namedConnection(config('database.default'));
    $connection->setReadWriteType('direct');

    // Laravel 13.33 adds the read/write type to getName() ("{name}::direct"); Laravel 12 returns the configured name.
    expect($connection->getName())->toBeIn([config('database.default'), config('database.default').'::direct'])
        ->and((new WritableTables)->for($connection))->toContain('cache', 'sessions');
});
