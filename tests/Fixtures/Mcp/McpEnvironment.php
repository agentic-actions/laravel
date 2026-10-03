<?php

namespace Tests\Fixtures\Mcp;

use AgenticActions\Exposure\ClassExposure;
use Closure;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\Team;

/**
 * The MCP tests' application: the fixtures in Actions/, the workbench's teams as tenants routed by slug, and the tenant
 * path mcp/t/{team}. The mount runs once at boot, so a test that needs other values boots again with bootMcp().
 */
trait McpEnvironment
{
    /**
     * Config values the next boot applies over the MCP defaults.
     *
     * @var array<string, mixed>
     */
    private array $mcpConfig = [];

    /**
     * Routes an app provider booted after the package's registers, or null.
     */
    private ?Closure $mcpAppRoutes = null;

    /**
     * The suite's environment, then the MCP defaults and this test's values. An application key, as every app has:
     * the request state of an MCP form is encrypted with it.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'agentic-actions.discovery.paths' => [__DIR__.'/Actions'],
            'agentic-actions.tenant.model' => Team::class,
            'agentic-actions.tenant.parameter' => 'team',
            'agentic-actions.tenant.membership' => McpMembership::class,
            'agentic-actions.mcp.tenant_path' => 'mcp/t/{team}',
            ...$this->mcpConfig,
        ]);

        if ($this->mcpAppRoutes !== null) {
            $app->register(new AppRoutes($app, $this->mcpAppRoutes));
        }
    }

    /**
     * Boot the application again with these values and app routes, before the test creates any data.
     *
     * @param  array<string, mixed>  $config
     */
    protected function bootMcp(array $config = [], ?Closure $appRoutes = null): void
    {
        $this->mcpConfig = $config;
        $this->mcpAppRoutes = $appRoutes;

        ClassExposure::flush();
        McpMembership::$asked = [];

        $this->reloadApplication();
    }

    /**
     * POST one JSON-RPC message, as a fresh request: the guards resolved by an earlier request are forgotten first, so
     * each token is really read.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function mcp(string $path, array $body, ?string $token = null, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($path, $body, [
            'Accept' => 'application/json, text/event-stream',
            ...($token === null ? [] : ['Authorization' => "Bearer {$token}"]),
            ...$headers,
        ]);
    }
}
