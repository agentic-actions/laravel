<?php

namespace AgenticActions;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;

/**
 * The provider Composer discovers: it merges the config and registers one provider per module.
 *
 * @api
 */
final class AgenticActionsServiceProvider extends ServiceProvider
{
    /**
     * The module providers, registered in this order.
     *
     * @var list<class-string<ServiceProvider>>
     */
    public const PROVIDERS = [
        CoreServiceProvider::class,
        Discovery\DiscoveryServiceProvider::class,
        Http\HttpServiceProvider::class,
        Console\ConsoleServiceProvider::class,
        TypeScript\TypeScriptServiceProvider::class,
        Testing\TestingServiceProvider::class,
        Streaming\StreamingServiceProvider::class,
        Mcp\McpServiceProvider::class,
        OAuth\OAuthServiceProvider::class,
        Feed\FeedServiceProvider::class,
    ];

    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->mergeConfig();

        foreach (self::PROVIDERS as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * Merge the app's config over the defaults one level deep: a group's keys merge, lists and scalars replace.
     */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $defaults = require __DIR__.'/../config/agentic-actions.php';
        $config = $this->app->make('config');
        $app = $config->get('agentic-actions', []);

        foreach ($defaults as $key => $default) {
            $app[$key] = is_array($default) && ! array_is_list($default) && is_array($app[$key] ?? null)
                ? array_merge($default, $app[$key])
                : (array_key_exists($key, $app) ? $app[$key] : $default);
        }

        $config->set('agentic-actions', $app);
    }

    /**
     * Bootstrap the package's services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agentic-actions.php' => config_path('agentic-actions.php'),
            ], 'agentic-actions-config');

            // Published on request only, file by file, and never loaded from here: an app that keeps no per-tenant
            // conversations never gets that table, one without OAuth the connections table, nor one without a copilot
            // the tables it showed.
            foreach ([
                'agentic-actions-migrations' => '2026_09_26_000001_create_agentic_conversations_table.php',
                'agentic-actions-oauth-migrations' => '2026_09_27_000001_create_agentic_mcp_connections_table.php',
                'agentic-actions-views-migrations' => '2026_09_30_000001_create_agentic_views_table.php',
            ] as $tag => $file) {
                $this->publishesMigrations([__DIR__."/../database/migrations/{$file}" => $this->app->databasePath("migrations/{$file}")], $tag);
            }
        }
    }
}
