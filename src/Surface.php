<?php

namespace AgenticActions;

/**
 * Where a call comes from: a route, an agent, an MCP client, the console, a queued run, or the app's own work.
 *
 * @api
 */
enum Surface: string
{
    case Http = 'http';
    case Agent = 'agent';
    case Mcp = 'mcp';
    case Console = 'console';
    case Queue = 'queue';
    case System = 'system';

    /**
     * Whether a model drives calls on this surface.
     */
    public function isModelDriven(): bool
    {
        return $this === self::Agent || $this === self::Mcp;
    }
}
