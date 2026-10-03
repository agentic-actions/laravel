<?php

use AgenticActions\AgenticActionsServiceProvider;

/**
 * Register the main provider again, which merges the app's config over the defaults.
 */
function registerAgain(): void
{
    (new AgenticActionsServiceProvider(app()))->register();
}

it('ships the mcp and feed defaults', function () {
    expect(config('agentic-actions.mcp'))->toBe([
        'path' => 'mcp/actions',
        'tenant_path' => null,
        'tenant_pattern' => null,
        'middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp'],
        'per_minute' => 60,
    ])->and(config('agentic-actions.feed'))->toBe(['enabled' => true, 'window' => 600]);
});

it('keeps a partial group\'s other defaults, and a null the app sets', function () {
    config(['agentic-actions' => [
        'agents' => ['forbidden_keys' => ['id']],
        'mcp' => ['path' => null, 'tenant_path' => 'mcp/t/{tenant}'],
        'feed' => ['enabled' => false],
    ]]);

    registerAgain();

    expect(config('agentic-actions.agents.forbidden_keys'))->toBe(['id'])
        ->and(config('agentic-actions.agents.forbidden_output_keys'))->toBe([])
        ->and(config('agentic-actions.agents.max_tools_per_toolset'))->toBe(20)
        ->and(config('agentic-actions.agents.max_message_length'))->toBe(4000)
        ->and(config('agentic-actions.routes'))->toBe(['path' => 'actions', 'name' => 'actions.'])
        ->and(config('agentic-actions.reads.guard'))->toBeTrue()
        ->and(config('agentic-actions.mcp'))->toBe([
            'path' => null,
            'tenant_path' => 'mcp/t/{tenant}',
            'tenant_pattern' => null,
            'middleware' => ['auth:sanctum', 'throttle:agentic-actions-mcp'],
            'per_minute' => 60,
        ])
        ->and(config('agentic-actions.feed'))->toBe(['enabled' => false, 'window' => 600]);
});

it('replaces a list the app sets whole, never merging it by index', function () {
    config(['agentic-actions' => [
        'discovery' => ['paths' => ['src']],
        'agents' => ['forbidden_keys' => ['id']],
    ]]);

    registerAgain();

    expect(config('agentic-actions.discovery.paths'))->toBe(['src'])
        ->and(config('agentic-actions.discovery.classes'))->toBe([])
        ->and(config('agentic-actions.agents.forbidden_keys'))->toBe(['id']);
});

it('keeps keys the app adds that the defaults lack', function () {
    config(['agentic-actions' => [
        'later' => ['enabled' => true],
        'agents' => ['extra' => 1],
    ]]);

    registerAgain();

    expect(config('agentic-actions.later'))->toBe(['enabled' => true])
        ->and(config('agentic-actions.agents.extra'))->toBe(1)
        ->and(config('agentic-actions.agents.max_tools_per_toolset'))->toBe(20);
});

it('replaces a scalar the app sets', function () {
    config(['agentic-actions' => ['snapshot' => 'custom.json', 'tenancy' => 'App\\Tenancy']]);

    registerAgain();

    expect(config('agentic-actions.snapshot'))->toBe('custom.json')
        ->and(config('agentic-actions.tenancy'))->toBe('App\\Tenancy')
        ->and(config('agentic-actions.surfaces'))->toBe(['web' => true, 'agents' => true, 'mcp' => true]);
});

it('leaves a cached configuration untouched', function () {
    // The application memoizes whether its configuration came from the cache file.
    app()->instance('config_loaded_from_cache', true);

    config(['agentic-actions' => ['agents' => ['forbidden_keys' => ['id']]]]);

    registerAgain();

    expect(config('agentic-actions'))->toBe(['agents' => ['forbidden_keys' => ['id']]]);
});
