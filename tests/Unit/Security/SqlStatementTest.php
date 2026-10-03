<?php

use AgenticActions\Security\SqlStatement;

it('allows statements that read', function (string $sql) {
    expect(SqlStatement::allowed($sql, []))->toBeTrue();
})->with([
    'select' => 'select * from "posts" where "id" = ?',
    'select with a trailing semicolon' => 'select 1;',
    'select with comments' => "-- leading comment\n/* block */ select * from posts -- trailing",
    'select mentioning a write in a string' => "select * from posts where title = 'delete from posts; insert into x'",
    'select for update' => 'select * from `posts` where `id` = ? for update',
    'parenthesised union' => '(select * from posts) union (select * from posts)',
    'with ... select' => 'with recent as (select * from posts order by id desc limit 5) select * from recent',
    'with recursive ... select' => 'with recursive n(i) as (select 1 union all select i + 1 from n where i < 3) select * from n',
    'with a bracketed name ... select' => 'with [recent] ([id]) as (select [id] from [posts]) select * from [recent]',
    'show tables' => 'show tables',
    'describe' => 'describe t',
    'desc' => 'desc t',
    'describe a column' => 'describe t title',
    'desc select' => 'desc select * from posts',
    'explain select' => 'explain select * from posts',
    'explain query plan' => 'explain query plan select * from posts',
    'explain with options' => 'explain (analyze, format json) select 1',
    'explain format' => 'explain format=json select 1',
    'pragma table_info' => 'pragma table_info(t)',
    'pragma table_info with schema' => 'pragma main.table_info("t")',
    'pragma index_list' => 'pragma index_list(t)',
    'pragma foreign_keys' => 'pragma foreign_keys',
    'pragma user_version' => 'pragma user_version',
    'savepoint' => 'savepoint a',
    'release' => 'release a',
    'a quoted identifier' => 'select "delete" from posts',
]);

it('denies statements that write or batch', function (string $sql) {
    expect(SqlStatement::allowed($sql, []))->toBeFalse();
})->with([
    'select into' => 'select * into t from posts',
    'select into outfile' => "select * from posts into outfile '/tmp/x'",
    'a batch' => 'select 1; delete from t',
    'a writing CTE' => 'with d as (delete from t returning *) select * from d',
    'a CTE whose main statement writes' => 'with x as (select 1) delete from t',
    'explain analyze update' => 'explain analyze update t set a = 1',
    'describe analyze delete' => 'describe analyze delete from t',
    'desc with a format and an update' => 'desc format=tree update t set a = 1',
    'pragma journal_mode = wal' => 'pragma journal_mode = wal',
    'pragma optimize' => 'pragma optimize',
    'pragma incremental_vacuum' => 'pragma incremental_vacuum',
    'pragma user_version = 3' => 'pragma user_version = 3',
    'begin' => 'begin',
    'commit' => 'commit',
    'rollback' => 'rollback',
    'insert into t' => 'insert into t (a) values (1)',
    'insert or ignore into "t"' => 'insert or ignore into "t" ("a") values (1)',
    'insert ignore into `t`' => 'insert ignore into `t` (`a`) values (1)',
    'replace into t' => 'replace into t (a) values (1)',
    'update [t] set' => 'update [t] set [a] = 1',
    'merge [t] using' => 'merge [t] using (values (1)) [s] ([a]) on [s].[a] = [t].[a] when matched then update set [a] = 1;',
    'delete `t` from `t` join' => 'delete `t` from `t` inner join `u` on `u`.`id` = `t`.`u_id`',
    'truncate table t' => 'truncate table t',
    'create table t' => 'create table t (a int)',
    'drop table t' => 'drop table t',
    'alter table t' => 'alter table t add column b int',
    'rename table' => 'rename table t to u',
    'vacuum' => 'vacuum',
    'an unclosed string' => "select 'abc",
    'an unclosed comment' => 'select 1 /* never closed',
    'a MySQL executable comment' => 'select 1 /*! delete from t */',
    'a MariaDB executable comment' => 'select 1 /*M! , (select 1 into outfile "/tmp/x") */',
    'nothing' => '',
    'a semicolon inside a dollar-quoted string, which some readings split on' => 'select $$; delete from t$$',
    'a batch hidden behind a backslash quote' => "select 'a\\'; delete from t; select '",
    'a batch hidden behind a dollar quote' => "select \$\$'\$\$; delete from t; select \$\$'\$\$",
    'a batch hidden behind a nested comment' => "select 1 /* /* */ ' */ ; delete from t; select '''",
    'a batch hidden behind a hash comment' => "select 1 # '\n; delete from t; select '",
    'a batch hidden behind a double-dash' => "select 1 --'\n; delete from t; select '",
]);

it('allows a SET that names only the current transaction\'s mode', function (string $sql) {
    expect(SqlStatement::allowed($sql, []))->toBeTrue();
})->with([
    'an isolation level' => 'set transaction isolation level read committed',
    'upper case, as SQL Server writes it' => 'SET TRANSACTION ISOLATION LEVEL SNAPSHOT',
    'read uncommitted' => 'set transaction isolation level read uncommitted',
    'repeatable read' => 'set transaction isolation level repeatable read',
    'read only' => 'set transaction read only',
    'a level and an access mode' => 'set transaction isolation level serializable, read write',
    'Postgres\'s deferrable' => 'set transaction isolation level serializable, read only, deferrable',
    'Postgres\'s not deferrable' => 'set transaction not deferrable',
    'Postgres\'s snapshot' => "set transaction snapshot '00000003-0000001B-1'",
    'Postgres\'s set local transaction' => 'set local transaction isolation level repeatable read',
    'a comment and a trailing semicolon' => '/* read */ set transaction read only;',
]);

it('denies a SET that changes the session or the server', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache', 'sessions']))->toBeFalse();
})->with([
    'autocommit' => 'set autocommit = 0',
    'autocommit through its variable' => 'set @@session.autocommit = 0',
    'foreign_key_checks' => 'set foreign_key_checks = 0',
    'sql_log_bin' => 'set sql_log_bin = 0',
    'sql_mode' => "set session sql_mode = ''",
    'sql_mode to its default' => 'set session sql_mode = default',
    'names' => 'set names latin1',
    'a time zone' => "set time_zone = '+05:00'",
    'a user variable' => 'set @rank = 0',
    'MySQL\'s session-wide transaction mode' => 'set session transaction isolation level read uncommitted',
    'Postgres\'s session characteristics' => 'set session characteristics as transaction isolation level read uncommitted',
    'a role' => 'set role admin',
    'no role' => 'set role none',
    'a session authorization' => "set session authorization 'admin'",
    'the session authorization back to its default' => 'set session authorization default',
    'a search path' => 'set search_path to other, public',
    'a search path for the transaction' => 'set local search_path = other',
    'a statement timeout' => 'set statement_timeout = 0',
    'Postgres\'s time zone' => "set time zone 'UTC'",
    'deferred constraints' => 'set constraints all deferred',
    'SQL Server\'s identity insert' => 'set identity_insert posts on',
    'SQL Server\'s xact_abort' => 'set xact_abort off',
    'a password' => "set password = 'secret'",
    'a global' => 'set global max_connections = 10',
    'a global through its variable' => 'set @@global.max_connections = 10',
    'persist' => 'set persist max_connections = 10',
    'persist_only' => 'set persist_only max_connections = 10',
    'a default role' => 'set default role all to u',
    'a transaction with no mode' => 'set transaction',
    'a local transaction with no mode' => 'set local transaction',
    'a transaction mode followed by a variable' => 'set transaction read only, @rank = 0',
    'a transaction mode followed by a session change' => 'set transaction read only, autocommit = 0',
    'a string that is not a snapshot' => "set transaction isolation level 'read committed'",
    'a transaction mode in a batch' => 'set transaction read only; set autocommit = 0',
    'a transaction mode hiding a batch behind a backslash quote' => "set transaction snapshot 'a\\'; set autocommit = 0; select '",
]);

it('allows writes to writable tables', function (string $sql, array $writable) {
    expect(SqlStatement::allowed($sql, $writable))->toBeTrue();
})->with([
    'insert into cache' => ['insert into cache (a) values (1)', ['cache']],
    'insert or ignore into "cache"' => ['insert or ignore into "cache" ("a") values (1)', ['cache']],
    'insert ignore into `cache`' => ['insert ignore into `cache` (`a`) values (1)', ['cache']],
    'replace into cache' => ['replace into cache (a) values (1)', ['cache']],
    'update [cache] set' => ['update [cache] set [a] = 1', ['cache']],
    'merge [cache] using' => ['merge [cache] using (values (1)) [s] ([a]) on [s].[a] = [cache].[a] when matched then update set [a] = 1;', ['cache']],
    'delete `cache` from `cache` join' => ['delete `cache` from `cache` inner join `u` on `u`.`id` = `cache`.`u_id`', ['cache']],
    'delete top (1) from [cache]' => ['delete top (1) from [cache] where [key] = ?', ['cache']],
    'truncate table cache' => ['truncate table cache', ['cache']],
    'create table cache' => ['create table if not exists cache (a int)', ['cache']],
    'drop table cache' => ['drop table if exists cache', ['cache']],
    'schema-qualified, as the app lists it' => ['insert into "public"."cache" ("a") values (1)', ['public.cache']],
    'prefixed' => ['delete from "app_cache" where "key" = ?', ['app_cache']],
    'a renamed cache table' => ['insert into "store_cache" ("key") values (?) on conflict ("key") do update set "value" = "excluded"."value"', ['store_cache']],
    'a writing CTE on a writable table' => ['with d as (delete from cache returning *) select * from d', ['cache']],
    'explain analyze update on a writable table' => ['explain analyze update cache set a = 1', ['cache']],
    'rename between writable tables' => ['rename table cache to cache_old', ['cache', 'cache_old']],
    'an update through an alias' => ['update "cache" as "c" set "value" = ?', ['cache']],
    'an update reading another table in a subquery' => ['update cache set a = (select title from posts limit 1)', ['cache']],
    'a delete reading another table in a subquery' => ['delete from cache where key in (select title from posts)', ['cache']],
    'a delete using another table' => ['delete from cache using posts where posts.id = cache.id', ['cache']],
    'an insert selecting from another table' => ['insert into cache (a) select title from posts', ['cache']],
    'describe analyze delete on a writable table' => ['describe analyze delete from cache', ['cache']],
]);

it('denies writes that could reach a table that is not writable', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache']))->toBeFalse();
})->with([
    'an update joining another table' => 'update `cache` inner join `posts` on `posts`.`id` = `cache`.`id` set `posts`.`title` = ?',
    'an update of two tables' => 'update cache, posts set posts.title = 1',
    'a delete of another table through an alias' => 'delete cache from posts as cache',
    'a truncate of two tables' => 'truncate cache, posts',
    'a drop of two tables' => 'drop table cache, posts',
    'a rename to another table' => 'rename table cache to posts',
    'an alter that renames to another table' => 'alter table cache rename to posts',
    'an update with a straight join' => 'update cache straight_join posts on posts.id = cache.id set posts.title = 1',
    'an update whose target is an alias of a second FROM' => 'update cache set title = 1 from posts as cache',
    'a delete whose target is an alias of a second FROM' => 'delete from cache from posts as cache',
    'a delete with OUTPUT INTO another table' => 'delete from cache output deleted.[key] into posts (title)',
    'an insert with OUTPUT INTO another table' => 'insert into cache (a) output inserted.a into posts (title) values (1)',
    'a truncate that cascades' => 'truncate cache cascade',
    'describe analyze delete on another table' => 'describe analyze delete from posts',
    'create index' => 'create index i on cache (a)',
    'insert into posts' => 'insert into posts (a) values (1)',
]);

it('names the table a writing statement writes', function (string $sql, ?string $table) {
    expect(SqlStatement::writeTarget($sql))->toBe($table);
})->with([
    ['insert into "posts" ("a") values (1)', 'posts'],
    ['insert or ignore into "Posts" ("a") values (1)', 'posts'],
    ['update [dbo].[posts] set [a] = 1', 'posts'],
    ['delete from `posts` where `id` = ?', 'posts'],
    ['delete `p` from `posts` as `p`', 'p'],
    ['truncate table only "public"."posts"', 'posts'],
    ['with d as (select 1) insert into posts select * from d', 'posts'],
    ['describe analyze delete from posts', 'posts'],
    ['select * from posts', null],
    ['begin', null],
]);

it('reads a backslash in a string as the connection\'s driver does', function (string $sql, array $allowedOn, array $refusedOn) {
    foreach ($allowedOn as $driver) {
        expect(SqlStatement::allowed($sql, [], $driver))->toBeTrue("allowed on {$driver}: {$sql}");
    }

    foreach ($refusedOn as $driver) {
        expect(SqlStatement::allowed($sql, [], $driver))->toBeFalse('refused on '.($driver ?? 'an unknown driver').": {$sql}");
    }
})->with([
    'a one-backslash LIKE escape, a literal backslash in standard SQL' => [
        "select * from \"posts\" where \"title\" like ? escape '\\'",
        ['sqlite', 'sqlsrv'],
        ['pgsql', 'mysql', 'mariadb', null, 'another'],
    ],
    'a LIKE escape of another character, as Postgres needs it' => [
        "select * from \"posts\" where \"title\" like ? escape '!'",
        ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null, 'another'],
        [],
    ],
    'a batch behind a backslash in a plain string, an escape on Postgres with standard_conforming_strings off' => [
        "select '\\' , '; delete from posts; -- '",
        ['sqlite', 'sqlsrv'],
        ['pgsql', 'mysql', 'mariadb', null],
    ],
    'a backslash in a double-quoted name, a literal on Postgres either way' => [
        'select "title\\" from "posts"',
        ['sqlite', 'pgsql', 'sqlsrv'],
        ['mysql', 'mariadb', null],
    ],
    'a two-backslash LIKE escape, as MySQL needs it with backslash escapes on' => [
        "select * from `posts` where `title` like ? escape '\\\\'",
        ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null],
        [],
    ],
    'Postgres\'s E\'...\' string, where a backslash escapes the quote' => [
        "select E'it\\'s' from posts",
        ['pgsql'],
        ['sqlite', 'sqlsrv'],
    ],
    'Postgres\'s lower-case e\'...\' string' => [
        "select e'it\\'s' from posts",
        ['pgsql'],
        ['sqlite', 'sqlsrv'],
    ],
    'a batch hidden behind Postgres\'s E\'...\' string, which elsewhere is a name and two strings' => [
        "select E'\\' , '; delete from posts; -- '",
        ['sqlite', 'sqlsrv'],
        ['pgsql', 'mysql', 'mariadb', null],
    ],
    'a batch behind a lower-case e\'...\' string' => [
        "select e'\\' , '; delete from posts; -- '",
        ['sqlite', 'sqlsrv'],
        ['pgsql', 'mysql', 'mariadb', null],
    ],
    'a word ending in e before a string, which is no E\'...\' string' => [
        "select name'\\' , '; delete from posts; -- '",
        ['sqlite', 'sqlsrv'],
        ['pgsql', 'mysql', 'mariadb', null],
    ],
]);

it('denies a batch a backslash would hide, on every driver', function (string $sql) {
    foreach (['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null, 'another'] as $driver) {
        expect(SqlStatement::allowed($sql, ['cache'], $driver))->toBeFalse('on '.($driver ?? 'an unknown driver').": {$sql}");
    }
})->with([
    'behind a backslash before the closing quote' => "select 'a\\'; delete from posts; select '",
    'behind a backslash in a double-quoted name' => 'select "a\\"; delete from posts; select "',
    'behind a trailing backslash' => "select '\\' ; delete from posts",
    'in a writing statement' => "insert into cache (a) values ('\\'); delete from posts; select '')",
]);

it('reads a line comment as ending at a carriage return too, as Postgres ends one', function (string $sql, array $refusedOn) {
    foreach ($refusedOn as $driver) {
        expect(SqlStatement::allowed($sql, ['cache'], $driver))->toBeFalse('on '.($driver ?? 'an unknown driver').": {$sql}");
    }
})->with([
    'a SELECT INTO behind a double-dash' => ["select * -- a note\rinto copied from posts", ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a batch behind a double-dash' => ["select 1 -- a note\r; delete from posts", ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a second T-SQL statement behind a double-dash' => ["select 1 -- a note\rdelete from posts", ['sqlsrv', null]],
]);

it('allows a line comment that ends in a carriage return and a line feed', function (?string $driver) {
    expect(SqlStatement::refusal("select * from posts -- a note\r\nwhere id = ? # another\r\n", [], $driver))->toBeNull();
})->with(['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]);

it('says whether a refused statement writes or could not be read', function (string $sql, ?string $driver, string $message) {
    expect(SqlStatement::refusal($sql, [], $driver)?->getMessage())->toStartWith($message);
})->with([
    'a write' => ['delete from posts', 'sqlite', 'A Read action tried to write [posts].'],
    'a write in the one reading that could be read' => ['delete from posts /* /* */', 'sqlite', 'A Read action tried to write [posts].'],
    'a string that never closes' => ["select 'abc", 'sqlite', 'A Read action sent a statement the Read guard could not check: ['."select 'abc]."],
    'a backslash that closes no string on MySQL' => ["select * from posts where title like ? escape '\\'", 'mysql', 'A Read action sent a statement the Read guard could not check'],
    'a backslash that closes no string on Postgres with standard_conforming_strings off' => ["select * from posts where title like ? escape '\\'", 'pgsql', 'A Read action sent a statement the Read guard could not check'],
    'an executable comment' => ['select 1 /*! , sleep(1) */', 'mysql', 'A Read action sent a statement the Read guard could not check'],
]);

it('has no refusal for a statement it allows', function () {
    expect(SqlStatement::refusal("select * from posts where title like ? escape '\\'", [], 'sqlite'))->toBeNull()
        ->and(SqlStatement::refusal('insert into cache (a) values (1)', ['cache'], 'pgsql'))->toBeNull();
});

it('allows a CTE whose name is quoted as a driver quotes names, on every driver', function (string $sql) {
    foreach (['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null, 'another'] as $driver) {
        expect(SqlStatement::refusal($sql, [], $driver))->toBeNull('on '.($driver ?? 'an unknown driver').": {$sql}");
    }
})->with([
    'a bracketed word' => 'with [recent] as (select 1 as x) select * from [recent]',
    'a bracketed name with a space' => 'with [recent posts] as (select 1 as x) select * from [recent posts]',
    'a bracketed number' => 'with [2024] as (select 1 as x) select * from [2024]',
    'a bracketed name with a hyphen, and bracketed columns' => 'with [recent-posts] ([post id]) as (select 1) select * from [recent-posts]',
    'a second bracketed CTE' => 'with [a] as (select 1 as x), [b c] as (select x from [a]) select * from [b c]',
    'a double-quoted name with a space' => 'with "recent posts" as (select 1 as x) select * from "recent posts"',
    'a backticked name with a space' => 'with `recent posts` as (select 1 as x) select * from `recent posts`',
]);

it('still refuses what a bracketed CTE name sits beside, on every driver', function (string $sql, array $refusedOn) {
    foreach ($refusedOn as $driver) {
        expect(SqlStatement::allowed($sql, [], $driver))->toBeFalse('on '.($driver ?? 'an unknown driver').": {$sql}");
    }
})->with([
    'a writing CTE body' => ['with [recent posts] as (delete from posts) select 1', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a writing main statement' => ['with [recent posts] as (select 1) delete from posts', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a SELECT INTO' => ['with [recent posts] as (select 1) select * into copied from [recent posts]', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a batch inside the brackets where they are not quotes' => ['with [a; delete from posts; ] as (select 1) select 1', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a second T-SQL statement' => ['with [recent posts] as (select 1) select * from [recent posts] delete from [posts]', ['sqlsrv', null]],
    'a qualified CTE name' => ['with [dbo].[recent] as (select 1) select 1', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
    'a bracket that never closes' => ['with [recent as (select 1) select 1', ['sqlite', 'pgsql', 'sqlsrv', 'mysql', 'mariadb', null]],
]);
