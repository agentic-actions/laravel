<?php

namespace AgenticActions\Security;

use AgenticActions\Contracts\ReadsTokenGrants;
use AgenticActions\Mcp\McpMount;
use AgenticActions\OAuth\McpConnection;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\AccessToken;
use Laravel\Passport\TransientToken as PassportTransientToken;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use WeakMap;

/**
 * The default token reader. It knows the session guard, Sanctum and Passport, and fails closed for every other guard.
 *
 * @internal
 */
final class TokenGrants implements ReadsTokenGrants
{
    /**
     * The guard names already noticed as unknown.
     *
     * @var array<string, true>
     */
    private array $noticed = [];

    /**
     * Each Passport token's connection, or false for none, looked up once per token object: every request's token is a
     * new object, so nothing outlives the request.
     *
     * @var WeakMap<AccessToken<mixed>, McpConnection|false>|null
     */
    private ?WeakMap $connections = null;

    /**
     * Read the grants the guard that authenticated the request gives.
     *
     * @return list<string>|null
     */
    public function grants(?Authenticatable $actor, Guard $guard): ?array
    {
        // The session driver, and only it.
        if ($guard instanceof SessionGuard) {
            return null;
        }

        $token = $actor !== null && method_exists($actor, 'currentAccessToken') ? $actor->currentAccessToken() : null;

        // Sanctum's and Passport's cookies read as a session.
        if ($token instanceof TransientToken || $token instanceof PassportTransientToken) {
            return null;
        }

        if ($token instanceof PersonalAccessToken) {
            return $this->sanctumAbilities($token);
        }

        if ($token instanceof AccessToken) {
            return $this->passport($token, $actor);
        }

        return [];
    }

    /**
     * How this reader treats a configured guard, from config("auth.guards.{$guard}.driver") alone: "session" for the
     * session driver, "sanctum" and "passport" for theirs, "unknown" otherwise. It never resolves the guard.
     */
    public function describe(string $guard): string
    {
        return match (config("auth.guards.{$guard}.driver")) {
            'session' => 'session',
            'sanctum' => 'sanctum',
            'passport' => 'passport',
            default => 'unknown',
        };
    }

    /**
     * Log, once per guard name, that a guard this reader does not know refused every action.
     */
    public function noticeUnknown(string $guard): void
    {
        if (isset($this->noticed[$guard])) {
            return;
        }

        $this->noticed[$guard] = true;

        Log::notice("agentic-actions: the [{$guard}] guard is unknown to the token reader, so every action reads as not found for its credentials. Bind AgenticActions\\Contracts\\ReadsTokenGrants to read its grants.");
    }

    /**
     * A Passport token's read and write scopes that its connection's approval named, only on the package MCP route
     * the connection names, plus its tenant grant. A token with no client or no connection, and every other scope,
     * grant nothing.
     *
     * @upstream A Passport token counts only on the MCP path its connection names.
     *
     * @param  AccessToken<mixed>  $token
     * @return list<string>
     */
    private function passport(AccessToken $token, ?Authenticatable $actor): array
    {
        $attributes = $token->toArray();
        $client = $attributes['oauth_client_id'] ?? null;
        $route = request()->route();

        if (! is_string($client) || $client === '' || ! $actor instanceof Model || ! $route instanceof Route || $route->getAction(McpMount::MARK) !== true) {
            return [];
        }

        $this->connections ??= new WeakMap;
        $connection = $this->connections[$token] ??= McpConnection::for($actor)->where('client_id', $client)->first() ?? false;
        $scopes = array_values(array_intersect((array) ($attributes['oauth_scopes'] ?? []), [config('agentic-actions.abilities.read'), config('agentic-actions.abilities.write')], $connection === false ? [] : (array) $connection->scopes));
        $prefix = (string) config('agentic-actions.abilities.tenant');
        $model = config('agentic-actions.tenant.model');

        return match (true) {
            $connection === false => [],
            $connection->tenant_type === null => $route->getName() === McpMount::ROUTE ? $scopes : [],
            $prefix === '' || ! is_string($model) || ! is_subclass_of($model, Model::class) || $connection->tenant_type !== (new $model)->getMorphClass() => [],
            default => [...$scopes, $prefix.$connection->tenant_id],
        };
    }

    /**
     * A token's stored abilities; for a test double that answers only can(), the abilities can() grants.
     *
     * @return list<string>
     */
    private function sanctumAbilities(PersonalAccessToken $token): array
    {
        $abilities = $token->getAttribute('abilities');

        if (is_array($abilities)) {
            return array_values(array_filter($abilities, is_string(...)));
        }

        if ($token->can('*')) {
            return ['*'];
        }

        $granted = [];

        foreach (['read', 'write', 'destructive', 'external'] as $effect) {
            $ability = config("agentic-actions.abilities.{$effect}");

            if (is_string($ability) && $token->can($ability)) {
                $granted[] = $ability;
            }
        }

        return $granted;
    }
}
