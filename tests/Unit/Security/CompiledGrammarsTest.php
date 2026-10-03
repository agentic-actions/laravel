<?php

use AgenticActions\Security\SqlStatement;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;

/**
 * The statements Laravel's own grammar compiles for one table: a select, an insert, an update, a delete and an upsert.
 *
 * @return array<string, string>
 */
function compiledStatements(Connection $connection, string $table): array
{
    $grammar = $connection->getQueryGrammar();
    $query = fn () => $connection->table($table)->where('key', 'k');
    $values = [['key' => 'k', 'value' => 'v', 'expiration' => 1]];

    return [
        'select' => $query()->select('value')->toSql(),
        'insert' => $grammar->compileInsert($connection->table($table), $values),
        'update' => $grammar->compileUpdate($query(), ['value' => 'v']),
        'delete' => $grammar->compileDelete($query()->limit(1)),
        'upsert' => $grammar->compileUpsert($connection->table($table), $values, ['key'], ['value', 'expiration']),
    ];
}

/**
 * A connection of each grammar that never connects.
 *
 * @return array<string, array{0: Closure(): Connection}>
 */
function grammarConnections(): array
{
    return [
        'MySQL' => [fn () => new MySqlConnection(fn () => throw new LogicException('Never connects.'), 'app', 'app_', ['name' => 'mysql', 'driver' => 'mysql'])],
        'Postgres' => [fn () => new PostgresConnection(fn () => throw new LogicException('Never connects.'), 'app', 'app_', ['name' => 'pgsql', 'driver' => 'pgsql'])],
        'SQLite' => [fn () => new SQLiteConnection(fn () => throw new LogicException('Never connects.'), ':memory:', 'app_', ['name' => 'sqlite', 'driver' => 'sqlite'])],
        'SQL Server' => [fn () => new SqlServerConnection(fn () => throw new LogicException('Never connects.'), 'app', 'app_', ['name' => 'sqlsrv', 'driver' => 'sqlsrv'])],
    ];
}

it('reads every statement a grammar compiles, as its own driver and as an unknown one', function (Closure $connect) {
    $connection = $connect();
    $cache = compiledStatements($connection, 'cache');
    $posts = compiledStatements($connection, 'posts');

    foreach ([$connection->getDriverName(), null] as $driver) {
        expect(SqlStatement::allowed($cache['select'], [], $driver))->toBeTrue()
            ->and(SqlStatement::allowed($posts['select'], [], $driver))->toBeTrue();

        foreach (['insert', 'update', 'delete', 'upsert'] as $write) {
            expect(SqlStatement::allowed($cache[$write], ['app_cache'], $driver))->toBeTrue("{$write} to the writable table: {$cache[$write]}")
                ->and(SqlStatement::allowed($cache[$write], [], $driver))->toBeFalse("{$write} with nothing writable: {$cache[$write]}")
                ->and(SqlStatement::allowed($posts[$write], ['app_cache'], $driver))->toBeFalse("{$write} to posts: {$posts[$write]}")
                ->and(SqlStatement::writeTarget($posts[$write]))->toBe('app_posts');
        }
    }
})->with(grammarConnections());

it('reads a hand-written LIKE escape as each grammar\'s own driver reads it', function (Closure $connect) {
    $connection = $connect();
    $grammar = $connection->getQueryGrammar();

    // A hand-written escape clause, spelling a one-character escape string as each database reads one: two backslashes
    // on MySQL, a character other than a backslash on Postgres (docs/security.md), one backslash elsewhere.
    $escape = match ($connection->getDriverName()) {
        'mysql' => "'\\\\'",
        'pgsql' => "'!'",
        default => "'\\'",
    };
    $sql = $connection->table('posts')->whereRaw($grammar->wrap('title')." like ? escape {$escape}", ['50\\%%'])->toSql();

    expect($connection->getDriverName())->not->toBeNull()
        ->and(SqlStatement::allowed($sql, [], $connection->getDriverName()))->toBeTrue($sql);
})->with(grammarConnections());

it('refuses a one-backslash LIKE escape on Postgres as a statement it could not check', function () {
    $connection = new PostgresConnection(fn () => throw new LogicException('Never connects.'), 'app', 'app_', ['name' => 'pgsql', 'driver' => 'pgsql']);
    $sql = $connection->table('posts')->whereRaw('"title" like ? escape \'\\\'', ['50\\%%'])->toSql();

    expect($sql)->toEndWith("escape '\\'")
        ->and(SqlStatement::refusal($sql, [], 'pgsql')?->getMessage())->toStartWith('A Read action sent a statement the Read guard could not check');
});

it('refuses the foreign-key switch each schema grammar compiles', function (Closure $connect) {
    $connection = $connect();
    $connection->useDefaultSchemaGrammar();
    $grammar = $connection->getSchemaGrammar();

    foreach ([$grammar->compileDisableForeignKeyConstraints(), $grammar->compileEnableForeignKeyConstraints()] as $sql) {
        expect(SqlStatement::allowed($sql, ['app_cache', 'app_posts']))->toBeFalse($sql)
            ->and(SqlStatement::allowed($sql, ['app_cache', 'app_posts'], $connection->getDriverName()))->toBeFalse($sql);
    }
})->with(grammarConnections());

it('reads the foreign-key pragma SQLite\'s schema grammar compiles, and refuses the one that sets it', function () {
    $connection = new SQLiteConnection(fn () => throw new LogicException('Never connects.'), ':memory:', 'app_', ['name' => 'sqlite', 'driver' => 'sqlite']);
    $connection->useDefaultSchemaGrammar();
    $grammar = $connection->getSchemaGrammar();

    expect($grammar)->toBeInstanceOf(SQLiteGrammar::class)
        ->and(SqlStatement::allowed($grammar->pragma('foreign_keys'), []))->toBeTrue()
        ->and(SqlStatement::allowed($grammar->pragma('foreign_keys', 0), []))->toBeFalse()
        ->and(SqlStatement::allowed($grammar->pragma('writable_schema', 1), []))->toBeFalse();
});
