<?php

namespace AgenticActions\Attributes;

use Attribute;

/**
 * On an agent that uses InteractsWithActions: the toolsets it receives. Bare, it means ["default"].
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UseToolset
{
    /**
     * The toolsets the agent receives.
     *
     * @var list<string>
     */
    public readonly array $names;

    /**
     * Name the toolsets.
     */
    public function __construct(string ...$names)
    {
        $this->names = $names === [] ? ['default'] : array_values(array_unique($names));
    }
}
