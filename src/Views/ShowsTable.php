<?php

namespace AgenticActions\Views;

/**
 * A Read action whose rows the person sees as a table. Its columns are the output allowlist of each row on every
 * surface, and handle() returns the rows: an iterable of arrays or objects, or a query the package limits. On an
 * action that is not a Read it changes nothing, and actions:check fails it.
 *
 * @api
 */
interface ShowsTable
{
    /**
     * The table's columns, in order. Their keys are the output allowlist of each row. Pure and cheap, like schema(),
     * and read inside the call's locale, so labels may use __().
     *
     * @return list<Column>
     */
    public function columns(): array;
}
