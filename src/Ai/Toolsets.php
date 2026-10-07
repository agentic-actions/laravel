<?php

namespace AgenticActions\Ai;

use AgenticActions\Attributes\DeferToolset;
use AgenticActions\Attributes\UseToolset;
use LogicException;
use ReflectionClass;

/**
 * Reads the toolsets an agent class receives from its own #[UseToolset] and #[DeferToolset].
 *
 * @internal
 */
final class Toolsets
{
    /**
     * The agent class's own #[UseToolset] names: the toolsets it loads on every step, none for a class that carries
     * only #[DeferToolset].
     *
     * @param  class-string  $agent
     * @return list<string>
     *
     * @throws LogicException when the class carries neither attribute
     */
    public static function of(string $agent): array
    {
        return self::declared($agent) ?? (self::deferred($agent) === []
            ? throw new LogicException("{$agent} has no #[UseToolset]: name the toolsets it receives.")
            : []);
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

    /**
     * The agent class's own #[DeferToolset] names, the toolsets it finds through tool search; none when it carries no
     * #[DeferToolset].
     *
     * @param  class-string  $agent
     * @return list<string>
     */
    public static function deferred(string $agent): array
    {
        $attribute = (new ReflectionClass($agent))->getAttributes(DeferToolset::class)[0] ?? null;

        return $attribute?->newInstance()->names ?? [];
    }
}
