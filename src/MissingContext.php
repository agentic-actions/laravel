<?php

namespace AgenticActions;

use LogicException;

/**
 * The context lacks what the action needs. A programming error: it renders as a crash on every surface.
 *
 * @api
 */
final class MissingContext extends LogicException
{
    /**
     * The context has no actor, or one of another type.
     */
    public static function actor(string $expected): self
    {
        return new self("The action context has no actor of type [{$expected}].");
    }

    /**
     * The context has no tenant, or one of another type.
     */
    public static function tenant(string $expected): self
    {
        return new self("The action context has no tenant of type [{$expected}].");
    }

    /**
     * A tenant-scoped action ran without a tenant.
     */
    public static function tenantScoped(string $action): self
    {
        return new self("The action [{$action}] is tenant-scoped, but its context has no tenant. Pass the tenant to the context, or set \$tenantScoped = false on the action.");
    }

    /**
     * A tenant model is configured but no scope class or closure is.
     */
    public static function scope(): self
    {
        return new self('A tenant model is configured, but no tenant scope is: set agentic-actions.tenant.scope, or call Actions::scopeUsing().');
    }
}
