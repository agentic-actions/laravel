<?php

namespace AgenticActions\Pipeline;

/**
 * How a call entered the pipeline. The door decides only whether the class's own #[Expose] is consulted.
 *
 * @internal
 */
enum Door: string
{
    /** run(), attempt(), actions:run: discovery alone reaches it. */
    case InProcess = 'in-process';

    /** A hand-written route: the route is its own allowlist. */
    case Route = 'route';

    /** Actions::routes(): the class must allow Http. */
    case GeneratedRoute = 'generated-route';

    /** Actions::call(): the class must allow Agent and share a toolset. */
    case Agent = 'agent';

    /** An MCP client's tools/call: the class must allow Mcp. */
    case Mcp = 'mcp';

    /**
     * Whether a model chose this call and its arguments: they are pruned to what was advertised, and translated.
     */
    public function isModel(): bool
    {
        return $this === self::Agent || $this === self::Mcp;
    }
}
