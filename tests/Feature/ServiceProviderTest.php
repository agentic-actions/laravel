<?php

namespace Tests\Feature;

use AgenticActions\AgenticActionsServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Boots the package provider on its own. It extends Testbench's TestCase rather than Tests\TestCase, so it needs
 * nothing but the provider and its config.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * Get the package providers.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [AgenticActionsServiceProvider::class];
    }

    public function test_the_provider_registers_every_module_provider(): void
    {
        foreach ([AgenticActionsServiceProvider::class, ...AgenticActionsServiceProvider::PROVIDERS] as $provider) {
            $this->assertTrue($this->app->providerIsLoaded($provider), "{$provider} is not loaded.");
        }
    }

    public function test_the_defaults_are_merged_into_the_config(): void
    {
        $this->assertSame(['app'], config('agentic-actions.discovery.paths'));
        $this->assertSame('actions', config('agentic-actions.routes.path'));
        $this->assertSame('actions.', config('agentic-actions.routes.name'));
        $this->assertNull(config('agentic-actions.tenant.model'));
        $this->assertSame(20, config('agentic-actions.agents.max_tools'));
        $this->assertSame('actions.exposure.json', config('agentic-actions.snapshot'));
    }

    public function test_the_config_file_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(AgenticActionsServiceProvider::class, 'agentic-actions-config');

        $this->assertSame([config_path('agentic-actions.php')], array_values($paths));
    }
}
