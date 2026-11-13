<?php

namespace AgenticActions\Exceptions;

use LogicException;

/**
 * discovery.groups names a path outside the app, an unknown key, an output two groups share, or a class two groups
 * both claim. Nothing is scanned while it is wrong, so no surface opens on a guess.
 *
 * @internal
 */
final class MisconfiguredDiscovery extends LogicException
{
    /**
     * One error, naming the group and the key.
     */
    public static function in(string $group, string $error): self
    {
        return new self("discovery.groups.{$group}: {$error}");
    }
}
