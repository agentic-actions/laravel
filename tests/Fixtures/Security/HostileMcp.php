<?php

namespace Tests\Fixtures\Security;

use Tests\Fixtures\Mcp\McpEnvironment;

/**
 * The MCP tests' application with the hostile fixtures discovered beside the MCP fixtures.
 */
trait HostileMcp
{
    use McpEnvironment {
        defineEnvironment as mcpEnvironment;
    }

    /**
     * The MCP environment, plus the hostile fixtures unless a test chose its own classes.
     */
    protected function defineEnvironment($app): void
    {
        $this->mcpEnvironment($app);

        if (! array_key_exists('agentic-actions.discovery.classes', $this->mcpConfig)) {
            $app['config']->set('agentic-actions.discovery.classes', [
                HostileRead::class,
                HostileWrite::class,
                HostileTeamWrite::class,
                HostileListing::class,
                HostilePublish::class,
            ]);
        }
    }
}
