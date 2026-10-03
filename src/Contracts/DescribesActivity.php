<?php

namespace AgenticActions\Contracts;

/**
 * A laravel/ai tool that shows a copilot row while it runs. Without it, or with a null label, a tool shows no row.
 *
 * @api
 */
interface DescribesActivity
{
    /**
     * The row's label while the tool runs ($finished false) and after it succeeded ($finished true). Null shows no row.
     * It takes no arguments and is read before its own call runs. One tool instance serves every call of a run, so
     * return fixed sentences and never read state a call left behind.
     */
    public function activityLabel(bool $finished): ?string;
}
