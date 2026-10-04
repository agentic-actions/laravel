<?php

namespace AgenticActions\Testing;

use AgenticActions\Facades\Actions;
use Laravel\Ai\Contracts\Agent;

/**
 * Assertions for a PHPUnit or Pest test case. Each one delegates to the Actions facade.
 *
 * @api
 */
trait ActionAssertions
{
    /**
     * Fail unless the toolset holds exactly these actions.
     *
     * @param  list<string>  $names
     */
    public function assertToolset(string $toolset, array $names): void
    {
        Actions::assertToolset($toolset, $names);
    }

    /**
     * Fail when one agent returns none of the action tools its toolsets give the person, or on duplicate tool names,
     * forbidden keys or an oversized toolset.
     */
    public function assertAgentTools(Agent $agent): void
    {
        Actions::assertAgentTools($agent);
    }
}
