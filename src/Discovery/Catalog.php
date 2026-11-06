<?php

namespace AgenticActions\Discovery;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Exposure\ClassExposure;
use AgenticActions\Exposure\Entry;
use AgenticActions\Pipeline\Door;
use AgenticActions\Runner;
use AgenticActions\Security\ForbiddenKeys;
use AgenticActions\Surface;
use Throwable;

/**
 * What a model surface may list for one context. The registry nominates; every filter reads what the class
 * declares now.
 *
 * @internal
 */
final class Catalog
{
    /**
     * The entries an agent with these toolsets may receive for this context.
     *
     * @param  list<string>  $toolsets
     * @return list<Entry>
     */
    public function forAgents(ActionContext $context, array $toolsets): array
    {
        if (! config('agentic-actions.surfaces.agents')) {
            return [];
        }

        return $this->listed(Surface::Agent, $context->withSurface(Surface::Agent), Door::Agent, $toolsets);
    }

    /**
     * The entries an MCP client may list for this context.
     *
     * @return list<Entry>
     */
    public function forMcp(ActionContext $context): array
    {
        if (! config('agentic-actions.surfaces.mcp')) {
            return [];
        }

        return $this->listed(Surface::Mcp, $context->withSurface(Surface::Mcp), Door::Mcp, []);
    }

    /**
     * The one entry an MCP client may call by this name for this context, or null: forMcp() for that name alone, so
     * a call runs every listing check without building the other tools.
     */
    public function mcpTool(ActionContext $context, string $name): ?Entry
    {
        if (! config('agentic-actions.surfaces.mcp')) {
            return null;
        }

        return $this->listed(Surface::Mcp, $context->withSurface(Surface::Mcp), Door::Mcp, [], $name)[0] ?? null;
    }

    /**
     * Live class facts, the surface, the toolsets (agents only), the tenant, steps 1 to 3 without input, then forbidden
     * keys. With tenancy on, MCP lists tenant-scoped actions only with a tenant and the rest only without one, the
     * split the mount makes. Forbidden keys run last because they build each advertised schema, so only entries the
     * caller may list pay for one.
     *
     * @param  list<string>  $toolsets
     * @param  string|null  $name  only the candidate of this name, when one tool is wanted
     * @return list<Entry>
     */
    private function listed(Surface $surface, ActionContext $context, Door $door, array $toolsets, ?string $name = null): array
    {
        return collect(app(ActionRegistry::class)->on($surface))
            ->filter(fn (Entry $candidate): bool => $name === null || $candidate->name === $name)
            ->map(fn (Entry $candidate): ?Entry => self::live($candidate))
            ->filter(fn (?Entry $entry): bool => $entry !== null && $entry->allows($surface))
            ->filter(fn (Entry $entry): bool => $door !== Door::Agent || array_intersect($entry->toolsets, $toolsets) !== [])
            ->filter(fn (Entry $entry): bool => $door === Door::Mcp && config('agentic-actions.tenant.model') !== null
                ? $entry->tenantScoped === ($context->tenant !== null)
                : ! $entry->tenantScoped || $context->tenant !== null)
            ->filter(fn (Entry $entry): bool => app(Runner::class)->exposed($entry, $context, $door, $toolsets))
            ->filter(fn (Entry $entry): bool => app(ForbiddenKeys::class)->advertisable($entry, $context))
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * What the class declares now, or null when a stale manifest nominates a class that is gone or now declares
     * another name: the model doors look tools up by the registry's names, so a renamed tool could never be called.
     */
    private static function live(Entry $candidate): ?Entry
    {
        try {
            $live = is_subclass_of($candidate->class, Action::class) ? ClassExposure::of($candidate->class) : null;
        } catch (Throwable) {
            return null;
        }

        return $live !== null && $live->name === $candidate->name ? $live : null;
    }
}
