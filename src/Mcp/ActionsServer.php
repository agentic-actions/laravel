<?php

namespace AgenticActions\Mcp;

use AgenticActions\ActionContext;
use AgenticActions\Discovery\Catalog;
use AgenticActions\Exposure\Entry;
use AgenticActions\Support\Packages;
use AgenticActions\Tenancy\Tenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;

/**
 * The package's MCP server. It is built for every request, so its tool list is always this caller's: the actions
 * agents could list, for the authenticated person, inside the URL's tenant when there is one.
 *
 * @internal
 */
final class ActionsServer extends Server
{
    /**
     * Name the server, write its instructions in the caller's locale, build this request's tools, and answer tools/call
     * with CallAction, so an asking action can ask a client that shows forms.
     */
    protected function boot(): void
    {
        $this->addMethod('tools/call', CallAction::class);

        $request = request();
        $actor = $request->user();

        // The locale is read before the tenant, so an unknown and a foreign tenant answer in the same language.
        $locale = $actor instanceof Authenticatable ? self::locale($actor) : app()->getLocale();
        $context = $this->context($request, $locale);

        $this->name = (string) config('app.name');
        $this->version = app(Packages::class)->version('agentic-actions/laravel') ?? '0.3';
        $this->instructions = (string) trans('agentic-actions::mcp.instructions', [], $locale);
        $this->tools = $context === null ? [] : array_map(
            fn (Entry $entry): McpTool => new McpTool($entry, $context),
            app(Catalog::class)->forMcp($context),
        );
    }

    /**
     * The caller's context, or null when nothing may be listed: MCP off, no actor, an unknown or a foreign tenant.
     */
    private function context(Request $request, string $locale): ?ActionContext
    {
        $actor = $request->user();

        if (! config('agentic-actions.surfaces.mcp') || ! $actor instanceof Authenticatable) {
            return null;
        }

        $segment = $request->route()?->parameter((string) config('agentic-actions.tenant.parameter'));
        $tenant = $segment === null ? null : McpMount::tenant($segment);

        // A tenant the actor may not enter takes the unknown tenant's path: the same answer after the same work, and
        // no per-action membership checks. A null effect is the listing question of ChecksMembership.
        if ($segment !== null && ($tenant === null || ! Tenants::member($actor, $tenant, null))) {
            return null;
        }

        return ActionContext::mcp($actor, $tenant, $locale);
    }

    /**
     * The actor's stored preference when it has one the translator can read, else the app's locale.
     */
    private static function locale(Authenticatable $actor): string
    {
        $preferred = $actor instanceof HasLocalePreference ? $actor->preferredLocale() : null;

        return is_string($preferred) && $preferred !== '' && strpbrk($preferred, '/\\') === false ? $preferred : app()->getLocale();
    }
}
