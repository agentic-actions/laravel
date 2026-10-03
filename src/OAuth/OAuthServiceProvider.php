<?php

namespace AgenticActions\OAuth;

use AgenticActions\Mcp\McpMount;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth module, on while McpMount::oauth() is: the consent view, the package's scopes in Passport's list, and the
 * package's consent screen when the app set no authorization view. McpMount attaches the middleware with the mount.
 *
 * @internal
 */
final class OAuthServiceProvider extends ServiceProvider
{
    /**
     * Load the view, and once the app booted, register the read and write abilities as Passport scopes, described in
     * plain words (the app's entries and descriptions win), and the consent screen when the app set no view.
     *
     * @upstream The package adds its scopes to Passport's list and keeps the app's.
     */
    public function boot(): void
    {
        if (! McpMount::oauth()) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'agentic-actions');

        $this->app->booted(function (): void {
            Passport::tokensCan([
                (string) config('agentic-actions.abilities.read') => (string) __('agentic-actions::oauth.abilities.read', ['app' => config('app.name')]),
                (string) config('agentic-actions.abilities.write') => (string) __('agentic-actions::oauth.abilities.write', ['app' => config('app.name')]),
                ...Passport::$scopes,
            ]);

            if (! $this->app->bound(AuthorizationViewResponse::class)) {
                Passport::authorizationView(fn (array $parameters): Response => Consent::from($parameters)->toResponse(request()));
            }
        });
    }
}
