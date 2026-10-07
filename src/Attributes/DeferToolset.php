<?php

namespace AgenticActions\Attributes;

use Attribute;

/**
 * On an agent that uses InteractsWithActions: the toolsets it finds through tool search, sent in one tool-search group
 * instead of on every step. Bare, it means ["default"].
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DeferToolset
{
    /**
     * The toolsets the agent finds through tool search.
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
