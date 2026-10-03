<?php

namespace AgenticActions\Security;

use AgenticActions\Exceptions\ReadActionWrote;

/**
 * Decides whether a SQL statement may run while a Read action's code runs. A backslash in a string is read each way
 * the connection's driver may read it, and the statement is read under every other quoting and comment convention the
 * supported databases use. It is allowed only when every reading allows it, so a statement cannot hide a write behind
 * a convention the database may use.
 *
 * @internal
 */
final class SqlStatement
{
    /**
     * The verbs that write, allowed only on a writable table.
     *
     * @var list<string>
     */
    private const WRITES = ['insert', 'replace', 'update', 'delete', 'merge', 'truncate', 'upsert', 'create', 'alter', 'drop', 'rename'];

    /**
     * Read-only pragmas, with or without a parenthesised argument.
     *
     * @var list<string>
     */
    private const PRAGMAS = ['table_info', 'table_xinfo', 'index_list', 'index_info', 'index_xinfo', 'foreign_key_list', 'database_list', 'compile_options'];

    /**
     * Read-only pragmas only when they take no argument and no "=".
     *
     * @var list<string>
     */
    private const BARE_PRAGMAS = ['foreign_keys', 'journal_mode', 'user_version', 'schema_version'];

    /**
     * The words a SET TRANSACTION may carry: an isolation level, an access mode, Postgres's DEFERRABLE and SNAPSHOT.
     *
     * @var list<string>
     */
    private const TRANSACTION_MODES = ['isolation', 'level', 'read', 'write', 'only', 'committed', 'uncommitted', 'repeatable', 'serializable', 'snapshot', 'not', 'deferrable'];

    /**
     * Words that, in a CREATE TABLE or ALTER TABLE, reach a table other than the one the statement names: partitions
     * (MySQL's EXCHANGE PARTITION, Postgres's ATTACH, DETACH and PARTITION OF) and Postgres's inheritance.
     *
     * @var list<string>
     */
    private const REACHES_ANOTHER_TABLE = ['partition', 'exchange', 'attach', 'detach', 'inherit', 'inherits'];

    /**
     * How each driver may read a backslash inside a string: as a literal ("none"), as an escape in every quoted string
     * ("all", MySQL's default), in single-quoted strings only ("single", where a double quote starts a name and a
     * backslash in a name is a literal), or only in Postgres's E'...' strings ("e"). MySQL and MariaDB may run with
     * NO_BACKSLASH_ESCAPES or ANSI_QUOTES, and Postgres with standard_conforming_strings on ("e") or off ("single"),
     * so the statement is read each way its server may read it. An unknown driver is read every way.
     *
     * @var array<string, list<string>>
     */
    private const BACKSLASHES = [
        'sqlite' => ['none'],
        'sqlsrv' => ['none'],
        'pgsql' => ['e', 'single'],
        'mysql' => ['none', 'all', 'single'],
        'mariadb' => ['none', 'all', 'single'],
    ];

    /**
     * How SQL Server reads a statement: a backslash is a literal, a bracket starts a name, block comments nest, "--"
     * starts a comment with or without a space after it, and "#" and "$" start nothing special. Whether a carriage
     * return ends its line comments is not known, so the readings with and without "cr" are both SQL Server's.
     *
     * @var array<string, bool|string>
     */
    private const SQL_SERVER = ['backslash' => 'none', 'bracket' => true, 'nested' => true, 'hash' => false, 'dollar' => false, 'dash' => false];

    /**
     * The words T-SQL starts a statement with, among those the allowed set holds. SQL Server runs a batch that starts
     * with any other word as a call to the procedure of that name.
     *
     * @var list<string>
     */
    private const SQL_SERVER_STARTS = ['select', 'with', 'set', 'insert', 'update', 'delete', 'merge', 'truncate', 'create', 'alter', 'drop'];

    /**
     * Words that start a T-SQL statement that writes, runs a procedure or other code, changes the session, begins or
     * ends a transaction, or waits. T-SQL needs no semicolon between two statements, so one of these after the first
     * word starts a second statement, unless the first statement's own grammar puts it there.
     *
     * @var list<string>
     */
    private const SQL_SERVER_STATEMENTS = [
        'insert', 'update', 'delete', 'merge', 'truncate', 'bulk', 'writetext', 'updatetext',
        'create', 'alter', 'drop', 'rename', 'enable', 'disable', 'grant', 'deny', 'revoke', 'add',
        'exec', 'execute', 'declare', 'set', 'use', 'setuser', 'revert', 'open', 'close', 'deallocate',
        'begin', 'commit', 'rollback', 'save', 'waitfor', 'send', 'receive', 'move',
        'backup', 'restore', 'dbcc', 'checkpoint', 'reconfigure', 'shutdown', 'kill',
    ];

    /**
     * The white space between tokens: ASCII's only, whatever the locale, as every supported database reads it outside
     * SQL Server, where a byte past ASCII belongs to a name.
     */
    private const WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * The conventions a reading can differ on, each with the text that makes it matter. "cr" is a line comment that a
     * carriage return ends, as on Postgres, and not only a line feed, as on MySQL and SQLite.
     *
     * @var array<string, string>
     */
    private const CONVENTIONS = [
        'dollar' => '$',
        'nested' => '/*',
        'hash' => '#',
        'bracket' => '[',
        'dash' => '--',
        'cr' => "\r",
    ];

    /**
     * Whether the statement may run during a Read on a connection whose writable tables are given. The driver is the
     * connection's getDriverName(); null, or a driver the reader does not know, reads it every way.
     *
     * @param  list<string>  $writable  lower-cased, prefixed table names
     */
    public static function allowed(string $sql, array $writable, ?string $driver = null): bool
    {
        return self::refusal($sql, $writable, $driver) === null;
    }

    /**
     * Why the statement may not run during a Read, as the exception the guard throws, or null when it may run. A
     * reading that shows a write, a batch or a statement outside the allowed set wins; otherwise a reading the reader
     * could not finish (a quote or comment that never closes, an executable comment, or in SQL Server's reading a letter
     * right after a number) refuses it as unchecked.
     *
     * @param  list<string>  $writable  lower-cased, prefixed table names
     */
    public static function refusal(string $sql, array $writable, ?string $driver = null): ?ReadActionWrote
    {
        $unreadable = false;

        foreach (self::readings($sql, $driver) as [$tokens, $sqlServer]) {
            if ($tokens === null) {
                $unreadable = true;
            } elseif (! self::statementAllowed($tokens, $writable, $sqlServer, $driver)) {
                return new ReadActionWrote(self::writeTarget($sql));
            }
        }

        return $unreadable ? ReadActionWrote::unchecked($sql) : null;
    }

    /**
     * The table a writing statement names, unquoted and without schema, or null when it names none.
     */
    public static function writeTarget(string $sql): ?string
    {
        $tokens = self::tokenize($sql, ['dollar' => true, 'bracket' => true]);

        if ($tokens === null) {
            return null;
        }

        $tokens = self::main(self::withoutTrailingBatch($tokens) ?? $tokens);

        if ($tokens === null || ! in_array(self::keyword($tokens), self::WRITES, true)) {
            return null;
        }

        $target = self::named(self::unwrapped($tokens))[0][0] ?? null;

        return $target === null ? null : substr((string) strrchr('.'.$target, '.'), 1);
    }

    /**
     * Every reading of the statement: each way its driver may read a backslash, crossed with the other conventions it
     * gives a reason to consider. Each comes as its tokens and whether it is SQL Server's own reading, on a driver
     * that may be SQL Server (sqlsrv, or one the reader does not know).
     *
     * @return list<array{0: list<array{0: string, 1: string}>|null, 1: bool}>
     */
    private static function readings(string $sql, ?string $driver): array
    {
        // Without a backslash every way of reading one gives the same tokens.
        $backslashes = str_contains($sql, '\\') ? self::BACKSLASHES[$driver ?? ''] ?? ['none', 'all', 'single', 'e'] : ['none'];
        $conventions = array_map(fn (string $backslash): array => ['backslash' => $backslash], $backslashes);

        foreach (self::CONVENTIONS as $convention => $trigger) {
            if (! str_contains($sql, $trigger)) {
                continue;
            }

            $expanded = [];

            foreach ($conventions as $reading) {
                $expanded[] = [...$reading, $convention => false];
                $expanded[] = [...$reading, $convention => true];
            }

            $conventions = $expanded;
        }

        $maybeSqlServer = $driver === 'sqlsrv' || ! isset(self::BACKSLASHES[$driver ?? '']);

        return array_map(function (array $reading) use ($sql, $maybeSqlServer): array {
            $reading['sqlsrv'] = $maybeSqlServer && array_diff_assoc(array_intersect_key($reading, self::SQL_SERVER), self::SQL_SERVER) === [];

            return [self::tokenize($sql, $reading), $reading['sqlsrv']];
        }, $conventions);
    }

    /**
     * Split a statement into tokens under one reading, or null when a string, identifier or comment never closes.
     * Comments are dropped; an executable comment makes the statement unreadable, and so, in SQL Server's reading, do a
     * letter right after a number, a word holding a character past ASCII, and a line comment holding a line break other
     * than CR or LF.
     *
     * @param  array<string, bool|string>  $reading
     * @return list<array{0: string, 1: string}>|null
     */
    private static function tokenize(string $sql, array $reading): ?array
    {
        $backslash = $reading['backslash'] ?? 'none';
        $length = strlen($sql);
        $tokens = [];
        $i = 0;

        while ($i < $length) {
            $character = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (str_contains(self::WHITESPACE, $character)) {
                $i++;

                continue;
            }

            $dashComment = $character === '-' && $next === '-'
                && (! ($reading['dash'] ?? false) || ! isset($sql[$i + 2]) || str_contains(self::WHITESPACE, $sql[$i + 2]));

            if ($dashComment || ($character === '#' && ($reading['hash'] ?? false))) {
                $comment = substr($sql, $i, strcspn($sql, ($reading['cr'] ?? false) ? "\r\n" : "\n", $i));

                // Which other line breaks end SQL Server's line comments is not documented: VT, FF, NEL, LS and PS.
                if (($reading['sqlsrv'] ?? false) && preg_match('/[\x0B\x0C]|\xC2\x85|\xE2\x80[\xA8\xA9]/', $comment) === 1) {
                    return null;
                }

                $i += strlen($comment) + 1;

                continue;
            }

            if ($character === '/' && $next === '*') {
                // An executable comment: MySQL's /*! ... */ and MariaDB's /*M! ... */ run their contents.
                if (($sql[$i + 2] ?? '') === '!' || strcasecmp(substr($sql, $i + 2, 2), 'm!') === 0) {
                    return null;
                }

                $i = self::commentEnd($sql, $i, $reading['nested'] ?? false);

                if ($i === null) {
                    return null;
                }

                continue;
            }

            // Postgres's E'...' string. A token starts here, so the E stands alone: in "name'...'" the word regex below
            // has already taken the whole name, and the string after it is a standard one, as Postgres reads it.
            if ($backslash === 'e' && ($character === 'e' || $character === 'E') && $next === "'") {
                $quoted = self::quoted($sql, $i + 1, "'", true);

                if ($quoted === null) {
                    return null;
                }

                $tokens[] = ['string', $quoted[0]];
                $i = $quoted[1];

                continue;
            }

            $closing = match (true) {
                $character === "'" => ["'", 'string', in_array($backslash, ['all', 'single'], true)],
                $character === '"' => ['"', 'name', $backslash === 'all'],
                $character === '`' => ['`', 'name', false],
                $character === '[' && ($reading['bracket'] ?? false) => [']', 'name', false],
                default => null,
            };

            if ($closing !== null) {
                $quoted = self::quoted($sql, $i, $closing[0], $closing[2]);

                if ($quoted === null) {
                    return null;
                }

                $tokens[] = [$closing[1], $quoted[0]];
                $i = $quoted[1];

                continue;
            }

            if ($character === '$' && ($reading['dollar'] ?? false) && preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $tag, 0, $i) === 1) {
                $end = strpos($sql, $tag[0], $i + strlen($tag[0]));

                if ($end === false) {
                    return null;
                }

                $tokens[] = ['string', substr($sql, $i + strlen($tag[0]), $end - $i - strlen($tag[0]))];
                $i = $end + strlen($tag[0]);

                continue;
            }

            if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $sql, $word, 0, $i) === 1) {
                // Which characters past ASCII SQL Server reads as white space is not documented, so in its reading a
                // word holding one is unfinished: a Unicode space never hides a second word inside a first.
                if (($reading['sqlsrv'] ?? false) && preg_match('/[\x80-\xff]/', $word[0]) === 1) {
                    return null;
                }

                $tokens[] = ['word', strtolower($word[0])];
                $i += strlen($word[0]);

                continue;
            }

            // A number ends where MySQL's lexer ends it, so "1.5into" reads as a number and INTO, as MySQL runs it. SQL
            // Server reads 0x and its hex digits as one number and starts a new word at a letter right after a number,
            // so it reads "0x1truncate" as 0x1 and TRUNCATE; it may also end "1exec" after the E, so its reading of a
            // number with a letter right after it is unfinished.
            $hex = ($reading['sqlsrv'] ?? false) ? '0[xX][0-9A-Fa-f]*|' : '';

            if (preg_match('/\G(?:'.$hex.'[0-9]+(?:\.[0-9]*)?(?:[eE][+-]?[0-9]+)?)/', $sql, $number, 0, $i) === 1) {
                $tokens[] = ['number', $number[0]];
                $i += strlen($number[0]);

                if (($reading['sqlsrv'] ?? false) && preg_match('/\G[A-Za-z_\x80-\xff]/', $sql, $letter, 0, $i) === 1) {
                    return null;
                }

                continue;
            }

            // MySQL reads \N as NULL and starts a new token after it, so "\Ninto" is NULL and INTO.
            if ($character === '\\' && ($next === 'N' || $next === 'n')) {
                $tokens[] = ['symbol', '\\N'];
                $i += 2;

                continue;
            }

            $tokens[] = ['symbol', $character];
            $i++;
        }

        return $tokens;
    }

    /**
     * The offset just after a block comment that starts at $start, or null when it never closes.
     */
    private static function commentEnd(string $sql, int $start, bool $nested): ?int
    {
        $length = strlen($sql);
        $depth = 1;
        $i = $start + 2;

        while ($i < $length && $depth > 0) {
            if ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/') {
                $depth--;
                $i += 2;
            } elseif ($nested && $sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                $depth++;
                $i += 2;
            } else {
                $i++;
            }
        }

        return $depth === 0 ? $i : null;
    }

    /**
     * A quoted string or identifier starting at $start: its unquoted value and the offset after it, or null when it
     * never closes. A doubled quote is always an escape; a backslash is one only when the reading says so.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function quoted(string $sql, int $start, string $quote, bool $backslash): ?array
    {
        $length = strlen($sql);
        $value = '';
        $i = $start + 1;

        while ($i < $length) {
            $character = $sql[$i];

            if ($backslash && $character === '\\') {
                $value .= $sql[$i + 1] ?? '';
                $i += 2;

                continue;
            }

            if ($character === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $value .= $quote;
                    $i += 2;

                    continue;
                }

                return [$value, $i + 1];
            }

            $value .= $character;
            $i++;
        }

        return null;
    }

    /**
     * Whether one reading of a statement may run: a single statement, and an allowed one. SQL Server's own reading
     * must also show no second statement after the first (startsSecondStatement()), and on sqlsrv the first word must
     * be one T-SQL starts a statement with, since SQL Server runs a batch that starts with any other word as a call to
     * the procedure of that name.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  list<string>  $writable
     */
    private static function statementAllowed(array $tokens, array $writable, bool $sqlServer, ?string $driver): bool
    {
        $tokens = self::withoutTrailingBatch($tokens);

        if ($tokens === null || ! self::tokensAllowed($tokens, $writable)) {
            return false;
        }

        if (! $sqlServer) {
            return true;
        }

        return ($driver !== 'sqlsrv' || in_array(self::keyword($tokens), self::SQL_SERVER_STARTS, true))
            && ! self::startsSecondStatement($tokens);
    }

    /**
     * Whether a second T-SQL statement starts inside this one, with no semicolon before it: a word from
     * SQL_SERVER_STATEMENTS, or END CONVERSATION, outside parentheses and after the first word of the main statement
     * (the one after a WITH's list, or the one an EXPLAIN or DESCRIBE names), unless it follows AS, as an alias does,
     * or belongs to the statement's own grammar. No T-SQL statement starts inside parentheses: a subquery is a SELECT,
     * and an EXEC's string or a WAITFOR's RECEIVE is reached through the word before the parenthesis.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  one allowed statement, without trailing semicolons
     */
    private static function startsSecondStatement(array $tokens): bool
    {
        $tokens = self::main($tokens);

        if ($tokens === null) {
            return true;
        }

        $start = count($tokens) - count(self::unwrapped($tokens));
        $keyword = self::keyword($tokens);
        $updateSet = $keyword === 'update';
        $depth = 0;

        foreach ($tokens as $i => $token) {
            $depth += match ($token) {
                ['symbol', '('] => 1,
                ['symbol', ')'] => -1,
                default => 0,
            };

            if ($i <= $start || $depth > 0 || $token[0] !== 'word') {
                continue;
            }

            // END CONVERSATION ends a Service Broker conversation; any other END closes a CASE.
            if ($token[1] === 'end' && ($tokens[$i + 1] ?? null) === ['word', 'conversation']) {
                return true;
            }

            if (! in_array($token[1], self::SQL_SERVER_STATEMENTS, true) || self::follows($tokens, $i, ['as'])) {
                continue;
            }

            // An UPDATE's own SET: the first one after its target.
            if ($updateSet && $token[1] === 'set') {
                $updateSet = false;

                continue;
            }

            if (! self::ownClause($tokens, $i, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the statement word at $i belongs to the statement it sits in. In T-SQL: a MERGE's THEN UPDATE, THEN
     * INSERT and THEN DELETE, and the SET after such an UPDATE. From other databases: a lock's FOR UPDATE and FOR NO
     * KEY UPDATE, MySQL's ON DUPLICATE KEY UPDATE and USE INDEX, and Postgres's and SQLite's ON CONFLICT (...) DO
     * UPDATE with its SET. SQL Server refuses each of those before the batch runs, since FOR, KEY and INDEX are
     * reserved words there and ON CONFLICT (...) calls a function it does not have.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function ownClause(array $tokens, int $i, ?string $keyword): bool
    {
        return match ($tokens[$i][1]) {
            'update' => self::setsMatchedRow($tokens, $i, $keyword)
                || self::follows($tokens, $i, ['for'])
                || self::follows($tokens, $i, ['for', 'no', 'key'])
                || self::follows($tokens, $i, ['on', 'duplicate', 'key']),
            'insert', 'delete' => $keyword === 'merge' && self::follows($tokens, $i, ['then']),
            'set' => self::follows($tokens, $i, ['update']) && self::setsMatchedRow($tokens, $i - 1, $keyword),
            'use' => in_array($tokens[$i + 1] ?? null, [['word', 'index'], ['word', 'key']], true),
            default => false,
        };
    }

    /**
     * Whether the UPDATE at $i is a MERGE's THEN UPDATE or an ON CONFLICT (...) DO UPDATE, the two a SET follows. A
     * SET after any other UPDATE the statement allows, such as FOR UPDATE, starts a second statement.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function setsMatchedRow(array $tokens, int $i, ?string $keyword): bool
    {
        return ($keyword === 'merge' && self::follows($tokens, $i, ['then']))
            || (self::follows($tokens, $i, ['do']) && self::closesConflictTarget($tokens, $i - 1));
    }

    /**
     * Whether the words just before $i are the given ones, in order.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  list<string>  $words
     */
    private static function follows(array $tokens, int $i, array $words): bool
    {
        foreach (array_reverse($words) as $offset => $word) {
            if (($tokens[$i - $offset - 1] ?? null) !== ['word', $word]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the DO at $do follows ON CONFLICT (...).
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function closesConflictTarget(array $tokens, int $do): bool
    {
        if (($tokens[$do - 1] ?? null) !== ['symbol', ')']) {
            return false;
        }

        $depth = 0;

        for ($i = $do - 1; $i >= 0; $i--) {
            $depth += match ($tokens[$i]) {
                ['symbol', ')'] => 1,
                ['symbol', '('] => -1,
                default => 0,
            };

            if ($depth === 0) {
                return self::follows($tokens, $i, ['on', 'conflict']);
            }
        }

        return false;
    }

    /**
     * The statement without trailing semicolons, or null when anything follows the first semicolon: a batch.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<array{0: string, 1: string}>|null
     */
    private static function withoutTrailingBatch(array $tokens): ?array
    {
        foreach ($tokens as $index => $token) {
            if ($token === ['symbol', ';']) {
                foreach (array_slice($tokens, $index) as $rest) {
                    if ($rest !== ['symbol', ';']) {
                        return null;
                    }
                }

                return array_slice($tokens, 0, $index);
            }
        }

        return $tokens;
    }

    /**
     * Whether a single statement's tokens may run. The first keyword decides.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  list<string>  $writable
     */
    private static function tokensAllowed(array $tokens, array $writable): bool
    {
        $keyword = self::keyword($tokens);
        $rest = array_slice(self::unwrapped($tokens), 1);

        return match (true) {
            $keyword === 'select' => ! self::contains($tokens, ['word', 'into']),
            $keyword === 'with' => self::withAllowed($rest, $writable),
            in_array($keyword, ['show', 'savepoint', 'release'], true) => true,
            $keyword === 'set' => self::setAllowed($rest),
            $keyword === 'explain' => self::tokensAllowed(self::explained($rest), $writable),
            $keyword === 'describe' || $keyword === 'desc' => self::describeAllowed($rest, $writable),
            $keyword === 'pragma' => self::pragmaAllowed($rest),
            in_array($keyword, self::WRITES, true) => self::writesOnly($tokens, $writable),
            default => false,
        };
    }

    /**
     * Whether every table a writing statement names is writable.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  list<string>  $writable
     */
    private static function writesOnly(array $tokens, array $writable): bool
    {
        $targets = self::targets($tokens);

        return $targets !== null && $targets !== [] && array_diff($targets, $writable) === [];
    }

    /**
     * Whether a statement that starts with WITH may run: every CTE body is itself allowed, and the main statement is a
     * select or an allowed write.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  the tokens after WITH
     * @param  list<string>  $writable
     */
    private static function withAllowed(array $tokens, array $writable): bool
    {
        $bodies = self::ctes($tokens);

        if ($bodies === null) {
            return false;
        }

        [$ctes, $main] = $bodies;

        foreach ($ctes as $body) {
            if (! self::tokensAllowed($body, $writable)) {
                return false;
            }
        }

        $keyword = self::keyword($main);

        return ($keyword === 'select' || in_array($keyword, self::WRITES, true)) && self::tokensAllowed($main, $writable);
    }

    /**
     * Split the tokens after WITH into the CTE bodies and the main statement, or null when they do not parse.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: list<list<array{0: string, 1: string}>>, 1: list<array{0: string, 1: string}>}|null
     */
    private static function ctes(array $tokens): ?array
    {
        $i = ($tokens[0] ?? null) === ['word', 'recursive'] ? 1 : 0;
        $bodies = [];

        while (true) {
            $name = self::cteName($tokens, $i);

            if ($name === null) {
                return null;
            }

            $i = $name;

            if (($tokens[$i] ?? null) === ['symbol', '(']) {
                $close = self::closing($tokens, $i);

                if ($close === null) {
                    return null;
                }

                $i = $close + 1;
            }

            if (($tokens[$i] ?? null) !== ['word', 'as']) {
                return null;
            }

            $i++;

            if (($tokens[$i] ?? null) === ['word', 'not']) {
                $i++;
            }

            if (($tokens[$i] ?? null) === ['word', 'materialized']) {
                $i++;
            }

            if (($tokens[$i] ?? null) !== ['symbol', '(']) {
                return null;
            }

            $close = self::closing($tokens, $i);

            if ($close === null) {
                return null;
            }

            $bodies[] = array_slice($tokens, $i + 1, $close - $i - 1);
            $i = $close + 1;

            if (($tokens[$i] ?? null) !== ['symbol', ',']) {
                return [$bodies, array_slice($tokens, $i)];
            }

            $i++;
        }
    }

    /**
     * The offset after the CTE name at $i, or null. Where brackets are not quotes, a bracketed name runs from "[" to the
     * next "]", so [recent posts] reads as one name there too. A WITH followed by "[" runs only on a database that
     * quotes with brackets, whose own reading checks the statement.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function cteName(array $tokens, int $i): ?int
    {
        $close = ($tokens[$i] ?? null) === ['symbol', '['] ? array_search(['symbol', ']'], array_slice($tokens, $i), true) : false;

        return is_int($close) ? $i + $close + 1 : (self::segment($tokens, $i)[1] ?? null);
    }

    /**
     * The statement an EXPLAIN describes, without its options.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  the tokens after EXPLAIN
     * @return list<array{0: string, 1: string}>
     */
    private static function explained(array $tokens): array
    {
        $i = 0;

        while (isset($tokens[$i])) {
            $token = $tokens[$i];

            if (in_array($token, [['word', 'analyze'], ['word', 'analyse'], ['word', 'verbose']], true)) {
                $i++;
            } elseif ($token === ['word', 'query'] && ($tokens[$i + 1] ?? null) === ['word', 'plan']) {
                $i += 2;
            } elseif ($token === ['symbol', '(']) {
                $close = self::closing($tokens, $i);

                if ($close === null) {
                    return [];
                }

                $i = $close + 1;
            } elseif ($token === ['word', 'format']) {
                $i += ($tokens[$i + 1] ?? null) === ['symbol', '='] ? 3 : 2;
            } else {
                break;
            }
        }

        return array_slice($tokens, $i);
    }

    /**
     * Whether a DESCRIBE or DESC may run. MySQL reads both as EXPLAIN, so an EXPLAIN option or a statement after them
     * follows the EXPLAIN rule; the table form (DESCRIBE posts [column]) only reads.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  the tokens after DESCRIBE or DESC
     * @param  list<string>  $writable
     */
    private static function describeAllowed(array $tokens, array $writable): bool
    {
        $described = self::explained($tokens);
        $statements = ['select', 'with', 'explain', 'describe', 'desc', ...self::WRITES];

        if ($described === $tokens && ! in_array(self::keyword($tokens), $statements, true)) {
            return true;
        }

        return self::tokensAllowed($described, $writable);
    }

    /**
     * Whether a SET may run: only SET TRANSACTION or SET LOCAL TRANSACTION, naming nothing but an isolation level, an
     * access mode, DEFERRABLE or a snapshot. Every other SET changes the session or the server (autocommit,
     * foreign_key_checks, sql_mode, a role, the search path, a variable, an account), which outlives the Read and
     * changes how later statements on the connection behave.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  the tokens after SET
     */
    private static function setAllowed(array $tokens): bool
    {
        $start = ($tokens[0] ?? null) === ['word', 'local'] ? 1 : 0;

        if (($tokens[$start] ?? null) !== ['word', 'transaction']) {
            return false;
        }

        $modes = array_slice($tokens, $start + 1);

        foreach ($modes as $index => $token) {
            $allowed = ($token[0] === 'word' && in_array($token[1], self::TRANSACTION_MODES, true))
                || $token === ['symbol', ',']
                || ($token[0] === 'string' && ($modes[$index - 1] ?? null) === ['word', 'snapshot']);

            if (! $allowed) {
                return false;
            }
        }

        return $modes !== [];
    }

    /**
     * Whether a PRAGMA is one of the named read-only ones.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  the tokens after PRAGMA
     */
    private static function pragmaAllowed(array $tokens): bool
    {
        $i = in_array($tokens[0][0] ?? null, ['word', 'name'], true) && ($tokens[1] ?? null) === ['symbol', '.'] ? 2 : 0;

        if (! in_array($tokens[$i][0] ?? null, ['word', 'name'], true)) {
            return false;
        }

        $name = strtolower($tokens[$i][1]);
        $after = array_slice($tokens, $i + 1);

        if (in_array($name, self::BARE_PRAGMAS, true)) {
            return $after === [];
        }

        if (! in_array($name, self::PRAGMAS, true)) {
            return false;
        }

        return $after === [] || (($after[0] ?? null) === ['symbol', '('] && self::closing($after, 0) === count($after) - 1);
    }

    /**
     * Every table a writing statement writes, lower-cased and without schema, or null when the statement's shape is
     * not one this reader understands (which denies it).
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<string>|null
     */
    private static function targets(array $tokens): ?array
    {
        $tokens = self::unwrapped($tokens);
        $named = self::named($tokens);

        if ($named === null) {
            return null;
        }

        [$tables, $start] = $named;
        $rest = array_slice($tokens, $start);

        // Past the verb phrase, INTO (SQL Server's OUTPUT ... INTO, a nested SELECT ... INTO) and CASCADE reach tables
        // the statement does not name, and so does a second top-level FROM in an UPDATE or DELETE, which SQL Server
        // reads as the table behind a target alias.
        if (self::contains($rest, ['word', 'into']) || self::contains($rest, ['word', 'cascade'])) {
            return null;
        }

        if (in_array(self::keyword($tokens), ['update', 'delete'], true) && self::containsTopLevel($rest, ['word', 'from'])) {
            return null;
        }

        // Partitions and inheritance move or expose another table's rows through the one a CREATE or ALTER names.
        if (in_array(self::keyword($tokens), ['create', 'alter'], true)) {
            foreach (self::REACHES_ANOTHER_TABLE as $word) {
                if (self::contains($rest, ['word', $word])) {
                    return null;
                }
            }
        }

        return $tables;
    }

    /**
     * The tables a writing statement names as its targets, and the offset where the last one starts, or null when the
     * statement's shape is not one this reader understands.
     *
     * @param  list<array{0: string, 1: string}>  $tokens  a statement without leading parentheses
     * @return array{0: list<string>, 1: int}|null
     */
    private static function named(array $tokens): ?array
    {
        $i = 1;

        switch (self::keyword($tokens)) {
            case 'insert':
            case 'upsert':
            case 'replace':
                return self::single($tokens, self::skip($tokens, $i, ['or', 'ignore', 'replace', 'rollback', 'abort', 'fail', 'low_priority', 'delayed', 'high_priority', 'into']));

            case 'merge':
                return self::single($tokens, self::skip($tokens, self::skipTop($tokens, $i), ['into']));

            case 'update':
                $i = self::skip($tokens, self::skipTop($tokens, $i), ['or', 'ignore', 'replace', 'rollback', 'abort', 'fail', 'low_priority', 'only']);
                $name = self::name($tokens, $i);

                if ($name === null) {
                    return null;
                }

                // Only an alias may stand between the table and SET: a join or a second table could write more.
                $j = self::skip($tokens, $name[1], ['as']);

                if (($tokens[$j] ?? null) !== ['word', 'set'] && self::segment($tokens, $j) !== null) {
                    $j = self::segment($tokens, $j)[1];
                }

                return ($tokens[$j] ?? null) === ['word', 'set'] ? [[$name[0]], $i] : null;

            case 'delete':
                $i = self::skip($tokens, self::skipTop($tokens, $i), ['low_priority', 'quick', 'ignore']);

                if (($tokens[$i] ?? null) === ['word', 'from']) {
                    return self::single($tokens, self::skip($tokens, $i + 1, ['only']));
                }

                // MySQL and SQL Server: DELETE alias FROM table ...; both must be writable.
                $alias = self::name($tokens, $i);

                if ($alias === null || ($tokens[$alias[1]] ?? null) !== ['word', 'from']) {
                    return null;
                }

                $table = self::single($tokens, self::skip($tokens, $alias[1] + 1, ['only']));

                return $table === null ? null : [[$alias[0], ...$table[0]], $table[1]];

            case 'truncate':
                return self::single($tokens, self::skip($tokens, $i, ['table', 'only']));

            case 'create':
                $i = self::skip($tokens, $i, ['or', 'replace', 'temporary', 'temp', 'unlogged', 'global', 'local']);

                return ($tokens[$i] ?? null) === ['word', 'table'] ? self::single($tokens, self::skip($tokens, $i + 1, ['if', 'not', 'exists'])) : null;

            case 'alter':
                // ALTER TABLE ... RENAME names a second table, as RENAME TABLE does.
                if (($tokens[$i] ?? null) !== ['word', 'table'] || self::contains($tokens, ['word', 'rename'])) {
                    return null;
                }

                return self::single($tokens, self::skip($tokens, $i + 1, ['if', 'exists', 'only']));

            case 'drop':
                $i = self::skip($tokens, $i, ['temporary']);

                return ($tokens[$i] ?? null) === ['word', 'table'] ? self::single($tokens, self::skip($tokens, $i + 1, ['if', 'exists'])) : null;

            case 'rename':
                if (($tokens[$i] ?? null) !== ['word', 'table'] || ($from = self::name($tokens, $i + 1)) === null) {
                    return null;
                }

                if (($tokens[$from[1]] ?? null) !== ['word', 'to'] || ($to = self::name($tokens, $from[1] + 1)) === null) {
                    return null;
                }

                return ($tokens[$to[1]] ?? null) === ['symbol', ','] ? null : [[$from[0], $to[0]], $from[1] + 1];

            default:
                return null;
        }
    }

    /**
     * The one table named at $i, and $i, or null when there is none, a second one follows (a comma), or the name
     * reaches further: MySQL's "cache.*" in a multi-table DELETE, or Postgres's "cache *", which adds its children.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: list<string>, 1: int}|null
     */
    private static function single(array $tokens, int $i): ?array
    {
        $name = self::name($tokens, $i);

        if ($name === null || in_array($tokens[$name[1]] ?? null, [['symbol', ','], ['symbol', '.'], ['symbol', '*']], true)) {
            return null;
        }

        return [[$name[0]], $i];
    }

    /**
     * A possibly schema-qualified name at $i: its segments, unquoted, lower-cased and joined with dots, and the offset
     * after it. A qualified name stays qualified, so "other"."cache" is never the app's own cache table.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: int}|null
     */
    private static function name(array $tokens, int $i): ?array
    {
        $segment = self::segment($tokens, $i);

        if ($segment === null) {
            return null;
        }

        $segments = [$segment[0]];

        while (($tokens[$segment[1]] ?? null) === ['symbol', '.'] && ($next = self::segment($tokens, $segment[1] + 1)) !== null) {
            $segment = $next;
            $segments[] = $segment[0];
        }

        return [strtolower(implode('.', $segments)), $segment[1]];
    }

    /**
     * One name segment at $i: a word, a quoted identifier, or a bracketed word in a reading where brackets are not
     * quotes (such a statement cannot run there, so reading its name is harmless).
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array{0: string, 1: int}|null
     */
    private static function segment(array $tokens, int $i): ?array
    {
        if (in_array($tokens[$i][0] ?? null, ['word', 'name'], true)) {
            return [$tokens[$i][1], $i + 1];
        }

        if (($tokens[$i] ?? null) === ['symbol', '['] && ($tokens[$i + 1][0] ?? null) === 'word' && ($tokens[$i + 2] ?? null) === ['symbol', ']']) {
            return [$tokens[$i + 1][1], $i + 3];
        }

        return null;
    }

    /**
     * The offset after any of the given words.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  list<string>  $words
     */
    private static function skip(array $tokens, int $i, array $words): int
    {
        while (($tokens[$i][0] ?? null) === 'word' && in_array($tokens[$i][1], $words, true)) {
            $i++;
        }

        return $i;
    }

    /**
     * The offset after SQL Server's TOP (n), when present.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function skipTop(array $tokens, int $i): int
    {
        if (($tokens[$i] ?? null) !== ['word', 'top']) {
            return $i;
        }

        if (($tokens[$i + 1] ?? null) === ['symbol', '(']) {
            return (self::closing($tokens, $i + 1) ?? $i) + 1;
        }

        return $i + 2;
    }

    /**
     * The offset of the parenthesis that closes the one at $open, or null.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function closing(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open; isset($tokens[$i]); $i++) {
            if ($tokens[$i] === ['symbol', '(']) {
                $depth++;
            } elseif ($tokens[$i] === ['symbol', ')'] && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The main statement for writeTarget(): a WITH's final statement, or the one an EXPLAIN or DESCRIBE names.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<array{0: string, 1: string}>|null
     */
    private static function main(array $tokens): ?array
    {
        return match (self::keyword($tokens)) {
            'with' => self::ctes(array_slice(self::unwrapped($tokens), 1))[1] ?? null,
            'explain', 'describe', 'desc' => self::main(self::explained(array_slice(self::unwrapped($tokens), 1))),
            default => $tokens,
        };
    }

    /**
     * The first keyword, after any opening parentheses.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function keyword(array $tokens): ?string
    {
        $first = self::unwrapped($tokens)[0] ?? null;

        return ($first[0] ?? null) === 'word' ? $first[1] : null;
    }

    /**
     * The tokens without leading opening parentheses, as a parenthesised union starts.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<array{0: string, 1: string}>
     */
    private static function unwrapped(array $tokens): array
    {
        $i = 0;

        while (($tokens[$i] ?? null) === ['symbol', '(']) {
            $i++;
        }

        return array_slice($tokens, $i);
    }

    /**
     * Whether the tokens contain the given token.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  array{0: string, 1: string}  $token
     */
    private static function contains(array $tokens, array $token): bool
    {
        return in_array($token, $tokens, true);
    }

    /**
     * Whether the tokens contain the given token outside any parentheses.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  array{0: string, 1: string}  $token
     */
    private static function containsTopLevel(array $tokens, array $token): bool
    {
        $depth = 0;

        foreach ($tokens as $each) {
            $depth += match ($each) {
                ['symbol', '('] => 1,
                ['symbol', ')'] => -1,
                default => 0,
            };

            if ($depth === 0 && $each === $token) {
                return true;
            }
        }

        return false;
    }
}
