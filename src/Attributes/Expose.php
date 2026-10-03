<?php

namespace AgenticActions\Attributes;

use Attribute;

/**
 * The only way onto a network or model surface. Bare, it means every surface the action's effect and shape allow;
 * with any argument, exactly the surfaces named. Read from the class's own attributes, so it is never inherited.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Expose
{
    /**
     * Name the surfaces, or none for every surface the effect and shape allow.
     *
     * @param  bool|null  $web  one POST route from Actions::routes()
     * @param  list<string>|null  $agents  the toolsets that receive the action; the only way a Destructive or External action reaches an agent
     * @param  bool|null  $mcp  the package's MCP server, under the rules agents follow (Read and Write, described)
     */
    public function __construct(
        public readonly ?bool $web = null,
        public readonly ?array $agents = null,
        public readonly ?bool $mcp = null,
    ) {}

    /**
     * Whether no argument was given.
     */
    public function isBare(): bool
    {
        return $this->web === null && $this->agents === null && $this->mcp === null;
    }
}
