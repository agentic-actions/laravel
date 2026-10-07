<?php

namespace AgenticActions\Security;

use AgenticActions\Exceptions\ReadActionWrote;
use Closure;
use Illuminate\Database\Connection;

/**
 * A Read cannot write through Laravel's database connection. While a Read action's own code runs, a statement that
 * would write is refused before it executes; while its initialize() runs, an INSERT that only adds rows to the tables
 * its $initializes lists runs too.
 *
 * @internal
 */
final class ReadGuard
{
    /**
     * How many guarded callbacks are running. Scoped to the request, so a nested Read stays guarded and an Octane
     * worker never carries state into the next request.
     */
    private int $depth = 0;

    /**
     * The tables the running initialize() may add rows to, as its action names them; empty everywhere else.
     *
     * @var list<string>
     */
    private array $insertable = [];

    /**
     * Run part of a Read's pipeline with writes refused. The tables an enclosing initialize() may add rows to do not
     * reach it, so a Read that initialize() runs is fully guarded again, and so is what that Read runs.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        [$depth, $insertable] = [$this->depth, $this->insertable];
        $this->depth++;
        $this->insertable = [];

        try {
            return $callback();
        } finally {
            [$this->depth, $this->insertable] = [$depth, $insertable];
        }
    }

    /**
     * Run a Read's initialize(): a statement that only adds rows to these tables runs too, and every other one is
     * refused as it is in handle(). Outside run(), as with reads.guard off, nothing is guarded, and this changes nothing.
     *
     * @template T
     *
     * @param  list<string>  $tables  as the action names them, without the connection's prefix
     * @param  Closure(): T  $callback
     * @return T
     */
    public function initializing(array $tables, Closure $callback): mixed
    {
        $previous = $this->insertable;
        $this->insertable = $tables;

        try {
            return $callback();
        } finally {
            $this->insertable = $previous;
        }
    }

    /**
     * Run a callback with the guard off, whatever the depth: the package's own events and their listeners.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function unguarded(Closure $callback): mixed
    {
        [$depth, $insertable] = [$this->depth, $this->insertable];
        $this->depth = 0;
        $this->insertable = [];

        try {
            return $callback();
        } finally {
            [$this->depth, $this->insertable] = [$depth, $insertable];
        }
    }

    /**
     * Whether a Read's own code is running now.
     */
    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Refuse a statement that would write, or that the guard cannot check, before it executes. The statement is read
     * as the connection's driver reads it. A no-op outside run().
     *
     * @throws ReadActionWrote
     */
    public function check(string $query, Connection $connection): void
    {
        if ($this->depth === 0) {
            return;
        }

        $tables = app(WritableTables::class);
        $insertable = $this->insertable === [] ? [] : $tables->named($this->insertable, $connection);

        $refusal = SqlStatement::refusal($query, $tables->for($connection), $connection->getDriverName(), $insertable);

        if ($refusal !== null) {
            throw $refusal;
        }
    }
}
