<?php

namespace AgenticActions\OAuth;

use AgenticActions\Mcp\McpMount;
use AgenticActions\Tenancy\Tenants;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's part of Passport's two authorization routes. For an MCP URL of the package (the RFC 8707 resource), the
 * request shows the consent screen every time, and only for a client and a tenant the package connects; approving it
 * records the connection, after revoking every token and code the client held for the person. Any other authorization
 * reaches Passport untouched, unless the person connected that client, which the package then refuses.
 *
 * @upstream The package records the MCP URL a person approves for a client.
 *
 * @internal
 */
final class BindConsent
{
    /**
     * The request attribute Consent::from() reads: array{route: string, tenant: Model|null}.
     */
    public const ATTRIBUTE = 'agentic-actions.resource';

    /**
     * The session entry between the screen and the approval: array{auth_token: string, client_id: string, user:
     * string, tenant_type: string|null, tenant_id: int|string|null, scopes: list<string>}.
     */
    public const SESSION = 'agentic-actions.consent';

    /**
     * GET and HEAD run the authorization checks, then send the frame-denying headers; POST runs the approval. Every
     * method but POST reads its parameters from the query, as Passport's authorization server does.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->user(config('passport.guard'));
        $client = $request->isMethod('POST') ? $request->input('client_id') : $request->query('client_id');

        if (! $person instanceof Model || ! $person instanceof Authenticatable || ! is_string($client) || $client === '') {
            return $next($request);
        }

        if ($request->isMethod('POST')) {
            return $this->approve($request, $next, $person, $client);
        }

        return tap($this->authorize($request, $next, $person, $client), fn (Response $response) => $response->headers->add(['X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "frame-ancestors 'none'"]));
    }

    /**
     * Check an authorization request for a package URL, force the screen and remember what it shows.
     */
    private function authorize(Request $request, Closure $next, Model&Authenticatable $person, string $client): Response
    {
        $path = is_string($resource = $request->query('resource')) ? McpMount::path($resource) : null;

        if ($path === null) {
            $request->session()->forget(self::SESSION);

            return McpConnection::for($person)->where('client_id', $client)->exists() ? self::refuse() : $next($request);
        }

        $scopes = array_filter(explode(' ', is_string($scope = $request->query('scope')) ? $scope : ''));
        $tenanted = $path[0] === McpMount::TENANT_ROUTE;

        abort_if($request->query('code_challenge_method') !== 'S256' || $scopes === [] || array_diff($scopes, [...McpMount::scopes($tenanted), 'mcp:use']) !== [], 400);

        $model = Passport::client()->newQuery()->find($client);
        $tenant = $tenanted ? McpMount::tenant($path[1]) : null;

        if ($model === null || array_diff((array) $model->getAttribute('grant_types'), ['authorization_code', 'refresh_token']) !== []
            || preg_grep('/[^\x21-\x5B\x5D-\x7E]/', (array) $model->getAttribute('redirect_uris')) !== [] || ($tenanted && ($tenant === null || ! Tenants::member($person, $tenant, null)))) {
            self::refuse();
        }

        $request->merge(['prompt' => 'consent']);
        $request->attributes->set(self::ATTRIBUTE, ['route' => $path[0], 'tenant' => $tenant]);
        $response = $next($request);

        if ($response->getStatusCode() === 200 && is_string($token = $request->session()->get('authToken'))) {
            $request->session()->put(self::SESSION, ['auth_token' => $token, 'client_id' => $client, 'user' => self::key($person), 'tenant_type' => $tenant?->getMorphClass(), 'tenant_id' => $tenant?->getKey(), 'scopes' => array_values($scopes)]);
        }

        return $response;
    }

    /**
     * Record the connection an approval matching the screen grants, in one transaction with Passport's code. The
     * approval matches only while Passport still holds the screen's token, so a denied screen's Allow changes nothing.
     */
    private function approve(Request $request, Closure $next, Model&Authenticatable $person, string $client): Response
    {
        $entry = $request->session()->pull(self::SESSION);
        $token = $request->input('auth_token');

        if (! is_array($entry) || ! is_string($token) || ! hash_equals((string) $entry['auth_token'], $token) || $request->session()->get('authToken') !== $token || $entry['client_id'] !== $client || $entry['user'] !== self::key($person)) {
            return is_array($entry) || McpConnection::for($person)->where('client_id', $client)->exists() ? self::refuse() : $next($request);
        }

        try {
            return (new McpConnection)->getConnection()->transaction(function () use ($request, $next, $person, $client, $entry): Response {
                $connection = McpConnection::for($person)->where('client_id', $client)->lockForUpdate()->first()
                    ?? (new McpConnection(['client_id' => $client]))->user()->associate($person);
                $connection->revokeTokens();
                $response = $next($request);
                parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

                if ($response->isRedirection() && isset($query['code'])) {
                    $connection->forceFill(['tenant_type' => $entry['tenant_type'], 'tenant_id' => $entry['tenant_id'], 'scopes' => $entry['scopes']])->save();
                }

                return $response;
            });
        } catch (QueryException) {
            self::refuse();
        }
    }

    /**
     * The person as the session entry names them.
     */
    private static function key(Model $person): string
    {
        return $person->getMorphClass().':'.$person->getKey();
    }

    /**
     * The one answer for every client, tenant and URL the package does not connect.
     */
    private static function refuse(): never
    {
        abort(403, __('agentic-actions::oauth.refused'));
    }
}
