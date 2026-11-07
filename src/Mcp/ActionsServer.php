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
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * The package's MCP server. It is built for every request, so its tool list is always this caller's: the actions
 * agents could list, for the authenticated person, inside the URL's tenant when there is one. The tools are built
 * only for the methods that read them: tools/list builds every listed tool, tools/call only the one it names, through
 * the same checks, and every other method (ping, initialize…) touches neither the tenant nor the catalog.
 *
 * @internal
 */
final class ActionsServer extends Server
{
    /**
     * The caller's locale, read when the server boots.
     */
    private string $locale = '';

    /**
     * The caller's context once a method needed it; false before.
     */
    private ActionContext|false|null $caller = false;

    /**
     * Name the server, write its instructions in the caller's locale, and answer tools/call with CallAction, so an
     * asking action can ask a client that shows forms.
     */
    protected function boot(): void
    {
        $this->addMethod('tools/call', CallAction::class);

        $actor = request()->user();

        // The locale is read before the tenant, so an unknown and a foreign tenant answer in the same language.
        $this->locale = $actor instanceof Authenticatable ? self::locale($actor) : app()->getLocale();

        $this->name = (string) config('app.name');
        $this->version = app(Packages::class)->version('agentic-actions/laravel') ?? '0.3';
        $this->instructions = (string) trans('agentic-actions::mcp.instructions', [], $this->locale);
    }

    /**
     * Build this request's tools for the methods that read them, then answer as laravel/mcp does.
     *
     * @upstream The tools are built here, for the method that reads them, rather than when the server boots.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        self::refuseWrongTypes($request);

        if (! in_array($request->method, ['tools/list', 'tools/call'], true)) {
            return parent::runMethodHandle($request, $context);
        }

        $caller = $this->caller();
        $name = $request->get('name');

        $entries = match (true) {
            $caller === null => [],
            $request->method === 'tools/list' => app(Catalog::class)->forMcp($caller),
            ! is_string($name) => [],
            default => array_filter([app(Catalog::class)->mcpTool($caller, $name)]),
        };

        $this->tools = $caller === null ? [] : array_map(fn (Entry $entry): McpTool => new McpTool($entry, $caller), array_values($entries));

        return parent::runMethodHandle($request, $this->createContext());
    }

    /**
     * Refuse a member of the wrong JSON type as invalid params, the client's mistake rather than the server's error.
     *
     * @upstream A tool name, prompt name, resource uri, cursor or page size of the wrong type answers invalid params.
     *
     * @throws JsonRpcException
     */
    private static function refuseWrongTypes(JsonRpcRequest $request): void
    {
        $members = match ($request->method) {
            'tools/call', 'prompts/get' => ['name' => 'a string'],
            'resources/read' => ['uri' => 'a string'],
            'tools/list', 'resources/list', 'resources/templates/list', 'prompts/list' => ['cursor' => 'a string', 'per_page' => 'an integer'],
            default => [],
        };

        foreach ($members as $member => $type) {
            $value = $request->get($member);

            if ($value !== null && ! ($type === 'a string' ? is_string($value) : is_int($value))) {
                throw new JsonRpcException("Invalid params: The [{$member}] member must be {$type}.", ErrorCode::INVALID_PARAMS->value, $request->id);
            }
        }
    }

    /**
     * The caller's context, worked out once per server.
     */
    private function caller(): ?ActionContext
    {
        if ($this->caller === false) {
            $this->caller = $this->context(request(), $this->locale);
        }

        return $this->caller;
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
