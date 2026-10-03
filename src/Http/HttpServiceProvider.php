<?php

namespace AgenticActions\Http;

use AgenticActions\Contracts\RendersOutcomes;
use Illuminate\Support\ServiceProvider;

/**
 * The HTTP module: the responder the FormRequest bridge renders through. Routes are registered only where the app
 * calls Actions::routes().
 *
 * @internal
 */
final class HttpServiceProvider extends ServiceProvider
{
    /**
     * Register the HTTP services.
     */
    public function register(): void
    {
        $this->app->singleton(RendersOutcomes::class, Responder::class);
    }
}
