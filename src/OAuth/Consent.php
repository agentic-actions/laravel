<?php

namespace AgenticActions\OAuth;

use AgenticActions\Approvals\ApprovalCard;
use AgenticActions\Effect;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a person approves when an OAuth client asks to connect, built from the parameters Passport hands its
 * authorization view. The package's page by default; pass toArray() to a page of your own, which posts approve and deny
 * as plain forms with their fields, since the answer redirects to the client.
 *
 * @api
 *
 * @implements Arrayable<string, mixed>
 */
final class Consent implements Arrayable, Responsable
{
    /**
     * Create the consent from the array toArray() documents.
     *
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * Build it from Passport's view parameters: client, user, scopes, request and authToken.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function from(array $parameters): self
    {
        /** @var array{client: Client, user: object, scopes: list<Scope>, request: Request, authToken: string} $parameters */
        ['client' => $client, 'request' => $request] = $parameters;
        $uri = is_string($uri = $request->query('redirect_uri')) ? $uri : (string) (((array) $client->getAttribute('redirect_uris'))[0] ?? '');
        [$scheme, $host] = [strtolower((string) parse_url($uri, PHP_URL_SCHEME)), (string) parse_url($uri, PHP_URL_HOST)];
        $web = in_array($scheme, ['http', 'https'], true);
        $tenant = $request->attributes->get(BindConsent::ATTRIBUTE)['tenant'] ?? null;
        $name = $tenant instanceof Model ? $tenant->getAttribute('name') : null;
        $url = route('passport.authorizations.approve');
        $fields = ['_token' => csrf_token(), 'state' => (string) $request->query('state'), 'client_id' => (string) $client->getKey(), 'auth_token' => $parameters['authToken']];

        return new self([
            'app' => ApprovalCard::text((string) config('app.name')),
            'client' => ApprovalCard::text((string) $client->getAttribute('name')),
            'redirect' => ['host' => $web ? $host : $scheme.'://', 'local' => ! $web || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)],
            'tenant' => $tenant instanceof Model ? ApprovalCard::text((string) (is_string($name) && $name !== '' ? $name : $tenant->getRouteKey())) : null,
            'abilities' => array_map(self::ability(...), $parameters['scopes']),
            'person' => ApprovalCard::text((string) (data_get($parameters['user'], 'email') ?? data_get($parameters['user'], 'name'))),
            'approve' => ['url' => $url, 'method' => 'POST', 'fields' => $fields],
            'deny' => ['url' => $url, 'method' => 'POST', 'fields' => [...$fields, '_method' => 'DELETE']],
        ]);
    }

    /**
     * The consent as an Inertia page or a view receives it: app, client, redirect {host, local}, tenant (its name, or
     * null), abilities (one sentence each), person, approve {url, method, fields} and deny {url, method, fields}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The package's page, agentic-actions::consent. An Inertia visit, such as a sign-in form's redirect back to the
     * authorization URL, gets Inertia's 409 naming that URL instead, so the browser loads the page as a full page.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->headers->has('X-Inertia')) {
            return response('', 409, ['X-Inertia-Location' => $request->fullUrl()]);
        }

        return response()->view('agentic-actions::consent', $this->data);
    }

    /**
     * One ability in plain words: the package's line for an effect's ability, else the scope's registered description.
     */
    private static function ability(Scope $scope): string
    {
        $effect = Effect::tryFrom((string) array_search($scope->id, (array) config('agentic-actions.abilities'), true));

        return $effect === null
            ? ApprovalCard::text($scope->description)
            : (string) __("agentic-actions::oauth.abilities.{$effect->value}", ['app' => config('app.name')]);
    }
}
