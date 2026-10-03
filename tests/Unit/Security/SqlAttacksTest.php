<?php

use AgenticActions\Security\SqlStatement;

/*
 * Statements a hostile or careless Read might send, each of which a supported database runs as a write. Every
 * reading of the statement must see the write, or the Read guard lets it through.
 */

it('sees INTO after a number literal, where MySQL starts a new token', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache']))->toBeFalse();
})->with([
    'a decimal' => "select 1.5into outfile '/tmp/x'",
    'an exponent' => "select 1e1into outfile '/tmp/x'",
    'a leading point' => 'select .5into @a',
    'a trailing point' => "select 1.into dumpfile '/tmp/x' from posts",
    'after a union' => "select title from posts where id = 1 union select 1.5into outfile '/tmp/x'",
    'after MySQL\'s \N null' => "select \\Ninto outfile '/tmp/x'",
]);

it('still reads number literals that write nothing', function (string $sql) {
    expect(SqlStatement::allowed($sql, []))->toBeTrue();
})->with([
    'decimals and exponents' => 'select 1.5, 2e10, 2.5e-3, .5, 1. from posts where id in (1, 2)',
    'a hex literal' => 'select 0x1F from posts',
    'a limit and an offset' => 'select * from "posts" limit 10 offset 20',
]);

it('denies a delete that names a second table through a wildcard', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache']))->toBeFalse();
})->with([
    'MySQL\'s multi-table form' => 'delete from cache.*, posts.* using cache, posts',
    'with a join' => 'delete from cache.*, posts.* using cache inner join posts on posts.id = cache.id',
    'a Postgres table and its children' => 'delete from cache * where 1 = 1',
    'a Postgres truncate of a table and its children' => 'truncate cache *',
]);

it('denies DDL on a writable table that moves or exposes another table\'s rows', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache']))->toBeFalse();
})->with([
    'MySQL\'s exchange partition' => 'alter table cache exchange partition p0 with table posts',
    'Postgres\'s attach partition' => 'alter table cache attach partition posts for values in (1)',
    'Postgres\'s detach partition' => 'alter table cache detach partition posts',
    'Postgres\'s inherit' => 'alter table cache inherit posts',
    'a partition of another table' => 'create table cache partition of posts for values in (1)',
    'a table that inherits another' => 'create temporary table cache () inherits (posts)',
]);

it('denies a schema-qualified table the app did not list by that name', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache', 'sessions']))->toBeFalse();
})->with([
    'another schema\'s cache' => 'insert into other.cache (a) values (1)',
    'another tenant\'s sessions' => 'insert into "tenant_b"."sessions" ("id") values (1)',
    'another database\'s cache' => 'delete from `shared`.`cache`',
]);

it('allows a schema-qualified table the app listed by that name', function () {
    expect(SqlStatement::allowed('insert into "public"."cache" ("a") values (1)', ['public.cache']))->toBeTrue()
        ->and(SqlStatement::allowed('update [dbo].[cache] set [a] = 1', ['dbo.cache']))->toBeTrue()
        ->and(SqlStatement::writeTarget('insert into "tenant_b"."sessions" ("id") values (1)'))->toBe('sessions');
});

it('sees a batch MySQL runs with ANSI_QUOTES on and backslash escapes left on', function (?string $driver) {
    // With ANSI_QUOTES, "\" is a name holding a backslash, while '\' , ' is still one string whose quote is escaped.
    $sql = "select \"\\\" , '\\' , ' ; delete from posts ; -- '\n\\\"x\"";

    expect(SqlStatement::allowed($sql, [], $driver))->toBeFalse();
})->with(['MySQL' => 'mysql', 'MariaDB' => 'mariadb', 'an unknown driver' => null]);
