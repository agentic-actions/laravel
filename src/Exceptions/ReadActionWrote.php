<?php

namespace AgenticActions\Exceptions;

use RuntimeException;

/**
 * A Read action sent a statement that would write through Laravel's database connection, or one the Read guard could
 * not check, or queued an action that is not a Read; or its initialize() sent one that does more than add rows to the
 * tables its $initializes lists. The statement never ran, and nothing was queued.
 *
 * @internal
 */
final class ReadActionWrote extends RuntimeException
{
    /**
     * Name the table the statement would have written, when it names one.
     */
    public function __construct(?string $table = null)
    {
        parent::__construct('A Read action tried to write ['.($table ?? 'a statement').']. The statement did not run: give the action a writing effect'
            .($table === null ? '.' : ', or list the table in agentic-actions.reads.writable_tables.'));
    }

    /**
     * A Read's initialize() sent a statement that does more than add rows to the tables its $initializes lists: one
     * that changes rows of those tables ($named), or one that writes anywhere else.
     */
    public static function initializing(?string $table, bool $named): self
    {
        $exception = new self;
        $exception->message = $named
            ? "A Read action's initialize() tried to change rows of [{$table}]. The statement did not run: initialize() only adds rows, and an update, delete, replace or upsert belongs in a Write action."
            : "A Read action's initialize() tried to write [".($table ?? 'a statement').']. The statement did not run: initialize() only adds rows to the tables its $initializes lists.';

        return $exception;
    }

    /**
     * A statement the guard could not read to the end under every way the connection may read it, so it could not
     * tell whether it writes. The message quotes the statement as Laravel sent it, with its bindings still apart.
     */
    public static function unchecked(string $sql): self
    {
        $exception = new self;
        $exception->message = "A Read action sent a statement the Read guard could not check: [{$sql}]. A quote or comment in it does not close"
            .' under every way the connection may read it, it holds an executable comment, or a letter follows a number with no space'
            .' between them, where SQL Server may start a new statement. The statement did not run.';

        return $exception;
    }

    /**
     * A Read action's own code tried to queue an action that is not a Read. Nothing was queued.
     */
    public static function queueing(string $class): self
    {
        $exception = new self;
        $exception->message = "A Read action tried to queue [{$class}], which is not a Read. Nothing was queued: give the calling action a writing effect.";

        return $exception;
    }
}
