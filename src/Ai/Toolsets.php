<?php

namespace AgenticActions\Ai;

use AgenticActions\Attributes\UseToolset;
use LogicException;
use ReflectionClass;

/**
 * Reads the toolsets an agent class receives from its own #[UseToolset].
 *
 * @internal
 */
final class Toolsets
{
    /**
     * The agent class's own #[UseToolset] names.
     *
     * @param  class-string  $agent
     * @return list<string>
     *
     * @throws LogicException when the class carries no #[UseToolset]
     */
    public static function of(string $agent): array
    {
        return self::declared($agent)
            ?? throw new LogicException("{$agent} has no #[UseToolset]: name the toolsets it receives.");
    }

    /**
     * The agent class's own #[UseToolset] names, or null when it carries none. Attributes are never inherited.
     *
     * @param  class-string  $agent
     * @return list<string>|null
     */
    public static function declared(string $agent): ?array
    {
        $attribute = (new ReflectionClass($agent))->getAttributes(UseToolset::class)[0] ?? null;

        return $attribute?->newInstance()->names;
    }
}
