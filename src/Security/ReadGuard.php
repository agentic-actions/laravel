<?php

namespace AgenticActions\Security;

use AgenticActions\Exceptions\ReadActionWrote;
use Closure;
use Illuminate\Database\Connection;

/**
 * A Read cannot write through Laravel's database connection. While a Read action's own code runs, a statement that
 * would write is refused before it executes.
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
     * Run part of a Read's pipeline with writes refused.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $previous = $this->depth;
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth = $previous;
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
        $previous = $this->depth;
        $this->depth = 0;

        try {
            return $callback();
        } finally {
            $this->depth = $previous;
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

        $refusal = SqlStatement::refusal($query, app(WritableTables::class)->for($connection), $connection->getDriverName());

        if ($refusal !== null) {
            throw $refusal;
        }
    }
}
