<?php

namespace AgenticActions\Exceptions;

use LogicException;

/**
 * Two actions share a name or a route segment.
 *
 * @internal
 */
final class DuplicateActionName extends LogicException
{
    /**
     * Name the two classes and what they share.
     */
    public static function between(string $first, string $second, string $name): self
    {
        return new self("Actions {$first} and {$second} share the name or route segment [{$name}].");
    }
}
