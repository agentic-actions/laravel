<?php

use AgenticActions\Security\SqlStatement;
use Illuminate\Database\Connection;
use Illuminate\Database\SqlServerConnection;

/*
 * T-SQL runs several statements in one batch with no semicolon between them. On sqlsrv, and on a driver the guard
 * does not know, a Read's statement is refused when a second statement that writes, runs code, changes the session or
 * a transaction, or waits follows it, and on sqlsrv also when its first word is not one T-SQL starts a statement with.
 */

/**
 * A SQL Server connection that never connects, with the "app_" table prefix.
 */
function sqlServerConnection(): SqlServerConnection
{
    return new SqlServerConnection(fn () => throw new LogicException('Never connects.'), 'app', 'app_', ['name' => 'sqlsrv', 'driver' => 'sqlsrv']);
}

it('refuses a second statement SQL Server would run after the first', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache'], 'sqlsrv'))->toBeFalse("on sqlsrv: {$sql}")
        ->and(SqlStatement::allowed($sql, ['cache']))->toBeFalse("on an unknown driver: {$sql}")
        ->and(SqlStatement::refusal($sql, ['cache'], 'sqlsrv')?->getMessage())->toStartWith('A Read action tried to write');
})->with([
    'a delete after a select' => 'select * from [posts] delete from [posts]',
    'an insert after a select' => "select * from [posts] insert into [posts] ([title]) values ('x')",
    'an insert without INTO' => "select 1 insert [posts] values ('x')",
    'an update after a select' => "select * from [posts] update [posts] set [title] = 'x'",
    'an exec of a procedure' => 'select * from [posts] exec [dbo].[purge_posts]',
    'an execute of a string' => "select 1 execute ('delete from posts')",
    'a declare and a set' => 'select 1 declare @n int set @n = 1',
    'a set of a session option' => 'select 1 set nocount on',
    'a merge into a table that is not writable' => 'select 1 merge [posts] using (values (1)) [s] ([id]) on [s].[id] = [posts].[id] when matched then delete;',
    'a delete after a CTE\'s select' => 'with recent as (select * from [posts]) select * from recent delete from [posts]',
    'a delete right after a subquery\'s closing parenthesis' => 'select * from [posts] where [id] in (select [post_id] from [comments])delete from [posts]',
    'a delete after a parenthesised select' => '(select * from [posts]) delete from [posts]',
    'a truncate' => 'select 1 truncate table [posts]',
    'a drop' => 'select 1 drop table [posts]',
    'a transaction' => 'select 1 begin transaction',
    'a commit' => 'select 1 commit',
    'a wait' => "select 1 waitfor delay '00:01'",
    'a use' => 'select 1 use [master]',
    'the end of a conversation' => 'select 1 end conversation ?',
    'a delete after an insert into a writable table' => 'insert into [cache] ([key]) values (?) delete from [posts]',
    'an update after an update of a writable table' => 'update [cache] set [value] = ? update [posts] set [title] = ?',
    'a second SET after an update of a writable table' => 'update [cache] set [value] = ? set nocount on',
    'a SET after a FOR UPDATE' => 'select * from [posts] for update set nocount on',
    'a SET after an ON DUPLICATE KEY UPDATE' => 'insert into [cache] ([key]) values (?) on duplicate key update set nocount on',
    'a delete after a bracketed CTE\'s select' => 'with [recent] as (select * from [posts]) select * from [recent] delete from [posts]',
]);

it('refuses a batch that starts with a word T-SQL runs as a procedure', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache'], 'sqlsrv'))->toBeFalse($sql);
})->with([
    'a bare procedure name' => 'sp_who',
    'a procedure with arguments' => "purge_posts 1, 'all'",
    'savepoint, a procedure named savepoint there' => 'savepoint a',
    'release' => 'release a',
    'show' => 'show tables',
    'describe' => 'describe posts',
    'explain' => 'explain select * from posts',
    'pragma' => 'pragma table_info(posts)',
    'replace' => 'replace into cache (a) values (1)',
]);

it('refuses a letter right after a number, where SQL Server starts a new word', function (string $sql) {
    expect(SqlStatement::refusal($sql, ['cache'], 'sqlsrv')?->getMessage())->toStartWith('A Read action sent a statement the Read guard could not check')
        ->and(SqlStatement::allowed($sql, ['cache']))->toBeFalse("on an unknown driver: {$sql}");
})->with([
    'a truncate after a hex number' => 'select 0x1truncate table [posts]',
    'an insert after an exponent with no digits' => "select 1einsert [posts] values ('x')",
    'an exec after a number' => 'select 1exec [purge_posts]',
    'a word after a decimal' => 'select 1.5from [posts]',
]);

it('refuses a character past ASCII outside quotes, where SQL Server may start a new word', function (string $sql) {
    expect(SqlStatement::refusal($sql, ['cache'], 'sqlsrv')?->getMessage())->toStartWith('A Read action sent a statement the Read guard could not check')
        ->and(SqlStatement::allowed($sql, ['cache']))->toBeFalse("on an unknown driver: {$sql}");
})->with([
    'a no-break space before a delete' => "select * from [posts]\u{00A0}delete from [posts]",
    'an ideographic space before a delete' => "select * from [posts]\u{3000}delete from [posts]",
    'a thin space before an exec' => "select * from [posts]\u{2009}exec [purge_posts]",
    'an en quad after a closing parenthesis' => "select * from [posts] where [id] in (select 1)\u{2000}truncate table [posts]",
    'a hair space after a comma' => "select [id],\u{200A}[title] from [posts]",
    'a line separator' => "select * from [posts]\u{2028}delete from [posts]",
    'a paragraph separator' => "select * from [posts]\u{2029}delete from [posts]",
    'a next line' => "select * from [posts]\u{0085}delete from [posts]",
    'a zero-width space inside a word' => "select * from [posts] de\u{200B}lete from [posts]",
    'a zero-width no-break space before a delete' => "select * from [posts]\u{FEFF}delete from [posts]",
    'a byte that is not UTF-8' => "select * from [posts]\xA0delete from [posts]",
    'a letter past ASCII in a name that is not quoted' => 'select * from منشورات',
]);

it('refuses a line comment holding a line break SQL Server may end it at', function (string $sql) {
    expect(SqlStatement::refusal($sql, ['cache'], 'sqlsrv')?->getMessage())->toStartWith('A Read action sent a statement the Read guard could not check')
        ->and(SqlStatement::allowed($sql, ['cache']))->toBeFalse("on an unknown driver: {$sql}");
})->with([
    'a line separator' => "select 1 -- a note\u{2028}delete from [posts]",
    'a paragraph separator' => "select 1 -- a note\u{2029}delete from [posts]",
    'a next line' => "select 1 -- a note\u{0085}delete from [posts]",
    'a vertical tab' => "select 1 -- a note\x0Bdelete from [posts]",
    'a form feed' => "select 1 -- a note\x0Cdelete from [posts]",
]);

it('reads text past ASCII in strings, quoted names and comments on sqlsrv, and past ASCII on the other drivers as before', function () {
    foreach ([
        "select N'مرحبا\u{00A0}بك\u{3000}' as [تحية]",
        "select [اسم\u{2009}المنشور] from \"منشورات\" where [title] = N'\u{2028}'",
        "select 1 /* \u{2028} \u{3000} */ -- ملاحظة\u{00A0}\nfrom [posts]",
    ] as $sql) {
        expect(SqlStatement::refusal($sql, [], 'sqlsrv'))->toBeNull("on sqlsrv: {$sql}");
    }

    foreach (['mysql', 'mariadb', 'pgsql', 'sqlite'] as $driver) {
        expect(SqlStatement::refusal("select * from posts\u{3000}where id = ?", [], $driver))->toBeNull("on {$driver}")
            ->and(SqlStatement::refusal("select 1 -- a note\u{2028}\nfrom posts", [], $driver))->toBeNull("on {$driver}");
    }
});

it('reads white space the same in every locale', function () {
    $sql = "select * from [posts]\xA0delete from [posts]";
    $previous = (string) setlocale(LC_CTYPE, '0');

    setlocale(LC_CTYPE, 'C');
    $messages = [SqlStatement::refusal($sql, [], 'sqlsrv')?->getMessage()];

    // A locale whose ctype reads the byte 0xA0 as a space, as macOS's UTF-8 locales and Latin-1 locales do.
    $spaced = setlocale(LC_CTYPE, 'en_US.UTF-8', 'ar_SA.UTF-8', 'en_US.ISO8859-1', 'en_US.ISO-8859-1', 'de_DE.ISO8859-1') !== false && ctype_space("\xA0");
    $messages[] = SqlStatement::refusal($sql, [], 'sqlsrv')?->getMessage();

    setlocale(LC_CTYPE, $previous);

    if (! $spaced) {
        $this->markTestSkipped('No installed locale reads 0xA0 as a space.');
    }

    expect($messages[1])->toBe($messages[0])
        ->and($messages[0])->toStartWith('A Read action sent a statement the Read guard could not check');
});

it('allows one statement on sqlsrv', function (string $sql) {
    expect(SqlStatement::refusal($sql, ['app_cache'], 'sqlsrv'))->toBeNull($sql);
})->with([
    'top (1)' => 'select top (1) * from [posts]',
    'top 1' => 'select top 1 * from [posts] order by [id] desc',
    'offset and fetch' => 'select * from [posts] order by [id] offset 0 rows fetch next 10 rows only',
    'a table hint' => 'select * from [posts] with (nolock) where [id] = ?',
    'a case expression' => "select case when [id] > 1 then 'many' else 'one' end as [kind] from [posts]",
    'statement words in a string' => "select * from [posts] where [title] = 'delete from posts exec sp_who update posts set a = 1'",
    'statement words in an N string' => "select * from [posts] where [title] = N'drop table posts'",
    'statement words in comments' => "select * from [posts] -- delete from posts\n/* exec sp_who /* update posts set a = 1 */ drop table posts */ where [id] = ?",
    'statement words as bracketed names' => 'select [delete], [exec], [update], [set] from [posts]',
    'a column alias [update]' => 'select [title] as [update] from [posts]',
    'a subquery' => 'select * from [posts] where [id] in (select [post_id] from [comments] where [body] is not null)',
    'a union' => 'select [id] from [posts] union all select [id] from [comments]',
    'a parenthesised union' => '(select [id] from [posts]) union (select [id] from [comments])',
    'a CTE' => 'with recent as (select top 5 * from [posts] order by [id] desc) select * from recent',
    'a CTE with a bracketed name' => 'with [recent] as (select top 5 * from [posts] order by [id] desc) select * from [recent]',
    'a hex number' => 'select 0x1F, 1e3, 2.5 from [posts]',
    'an isolation level' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
    'a trailing semicolon' => 'select * from [posts];',
    'a delete from a writable table' => 'delete top (1) from [app_cache] where [key] = ?',
]);

it('allows every select the SQL Server grammar compiles', function (Closure $query) {
    $sql = $query(sqlServerConnection())->toSql();

    expect(SqlStatement::refusal($sql, [], 'sqlsrv'))->toBeNull($sql);
})->with([
    'a limit' => [fn (Connection $db) => $db->table('posts')->where('id', 1)->limit(1)],
    'an offset' => [fn (Connection $db) => $db->table('posts')->orderBy('id')->offset(10)->limit(5)],
    'an offset with no order' => [fn (Connection $db) => $db->table('posts')->offset(10)->limit(5)],
    'a lock for update' => [fn (Connection $db) => $db->table('posts')->where('id', 1)->lockForUpdate()],
    'a shared lock' => [fn (Connection $db) => $db->table('posts')->sharedLock()],
    'a lock written as a string' => [fn (Connection $db) => $db->table('posts')->lock('with(nolock)')],
    'a forced index' => [fn (Connection $db) => $db->table('posts')->forceIndex('posts_title_index')],
    'an aggregate' => [fn (Connection $db) => $db->table('posts')->selectRaw('count(*) as aggregate')],
    'a random order' => [fn (Connection $db) => $db->table('posts')->inRandomOrder()],
    'a date' => [fn (Connection $db) => $db->table('posts')->whereDate('created_at', '2026-01-01')],
    'a JSON contains' => [fn (Connection $db) => $db->table('posts')->whereJsonContains('tags', 'laravel')],
    'a JSON length' => [fn (Connection $db) => $db->table('posts')->whereJsonLength('tags', '>', 1)],
    'a union' => [fn (Connection $db) => $db->table('posts')->select('id')->union($db->table('comments')->select('id'))],
    'a join and a group' => [fn (Connection $db) => $db->table('posts')->join('comments', 'comments.post_id', '=', 'posts.id')->groupBy('posts.id')->having('posts.id', '>', 1)->select('posts.id')],
    'a subquery' => [fn (Connection $db) => $db->table('posts')->whereIn('id', $db->table('comments')->select('post_id'))],
    'distinct' => [fn (Connection $db) => $db->table('posts')->distinct()->select('title')],
]);

it('allows the cache upsert SQL Server\'s grammar compiles, into the writable cache table only', function () {
    $connection = sqlServerConnection();
    $upsert = fn (string $table): string => $connection->getQueryGrammar()->compileUpsert(
        $connection->table($table), [['key' => 'k', 'value' => 'v', 'expiration' => 1]], ['key'], ['value', 'expiration'],
    );

    expect($upsert('cache'))->toStartWith('merge [app_cache] using')
        ->and($upsert('cache'))->toContain('when matched then update set')
        ->and($upsert('cache'))->toContain('when not matched then insert')
        ->and(SqlStatement::refusal($upsert('cache'), ['app_cache'], 'sqlsrv'))->toBeNull()
        ->and(SqlStatement::refusal($upsert('cache'), ['app_cache']))->toBeNull()
        ->and(SqlStatement::allowed($upsert('posts'), ['app_cache'], 'sqlsrv'))->toBeFalse()
        ->and(SqlStatement::allowed($upsert('cache'), [], 'sqlsrv'))->toBeFalse();
});

it('reads the other drivers as before', function (string $driver, string $allowed, string $refused) {
    expect(SqlStatement::refusal($allowed, ['cache'], $driver))->toBeNull("allowed on {$driver}: {$allowed}")
        ->and(SqlStatement::allowed($refused, ['cache'], $driver))->toBeFalse("refused on {$driver}: {$refused}");
})->with([
    'MySQL' => ['mysql', 'insert into `cache` (`key`) values (?) on duplicate key update `value` = values(`value`)', 'insert into `posts` (`title`) values (?) on duplicate key update `title` = values(`title`)'],
    'MariaDB' => ['mariadb', 'select * from `posts` where `id` = ? lock in share mode', 'select * from `posts`; delete from `posts`'],
    'Postgres' => ['pgsql', 'insert into "cache" ("key") values (?) on conflict ("key") do update set "value" = "excluded"."value"', 'with d as (delete from "posts" returning *) select * from d'],
    'SQLite' => ['sqlite', 'pragma table_info("posts")', 'pragma foreign_keys = 0'],
]);

it('applies the SQL Server rule to no other driver', function (string $driver) {
    foreach (['select * from posts for update', 'savepoint a', 'alter table cache add column b int', 'select * from 1st_posts'] as $sql) {
        expect(SqlStatement::refusal($sql, ['cache'], $driver))->toBeNull("allowed on {$driver}: {$sql}");
    }

    expect(SqlStatement::allowed('savepoint a', ['cache'], 'sqlsrv'))->toBeFalse()
        ->and(SqlStatement::allowed('alter table cache add column b int', ['cache'], 'sqlsrv'))->toBeFalse()
        ->and(SqlStatement::allowed('select * from 1st_posts', ['cache'], 'sqlsrv'))->toBeFalse();
})->with(['mysql', 'mariadb', 'pgsql', 'sqlite']);

it('keeps the clauses other databases write on a driver the guard does not know', function (string $sql) {
    expect(SqlStatement::refusal($sql, ['cache']))->toBeNull($sql);
})->with([
    'FOR UPDATE' => 'select * from `posts` where `id` = ? for update',
    'FOR NO KEY UPDATE' => 'select * from "posts" for no key update',
    'ON DUPLICATE KEY UPDATE' => 'insert into `cache` (`key`) values (?) on duplicate key update `value` = values(`value`)',
    'ON CONFLICT (...) DO UPDATE' => 'insert into "cache" ("key") values (?) on conflict ("key") do update set "value" = "excluded"."value"',
    'USE INDEX' => 'select * from `posts` use index (`posts_title_index`) where `title` = ?',
    'a SET after an update\'s alias' => 'update "cache" as "c" set "value" = ?',
    'EXPLAIN of an update of a writable table' => 'explain analyze update cache set a = 1',
]);

it('refuses a DO UPDATE that no ON CONFLICT (...) comes before', function (string $sql) {
    expect(SqlStatement::allowed($sql, ['cache'], 'sqlsrv'))->toBeFalse($sql)
        ->and(SqlStatement::allowed($sql, ['cache']))->toBeFalse($sql);
})->with([
    'a table aliased do' => 'select * from [posts] do update [posts] set [title] = ?',
    'a parenthesis before do that closes something else' => 'select * from [posts] where [id] in (1) do update [posts] set [title] = ?',
]);
