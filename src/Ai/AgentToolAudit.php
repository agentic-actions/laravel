<?php

namespace AgenticActions\Ai;

use AgenticActions\Attributes\WithPageContext;
use AgenticActions\Security\ForbiddenKeys;
use AgenticActions\Streaming\PageContext;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\ToolNameResolver;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * Audits one agent's tools as laravel/ai resolves them: unique names, no forbidden input keys, toolsets within
 * agents.max_tools_per_toolset, and the page context wired when the agent asks for it. Every check goes through
 * PHPUnit's Assert, so it works under PHPUnit and Pest.
 *
 * @upstream Tools are resolved the way the provider resolves them before a turn.
 *
 * @internal
 */
final class AgentToolAudit
{
    /**
     * Assert unique tool names, no forbidden keys and toolsets within the limit, over the tools as laravel/ai
     * resolves them, then the page context's wiring.
     */
    public function assert(Agent $agent): void
    {
        $tools = $this->resolve($agent instanceof HasTools ? [...$agent->tools()] : []);

        $this->assertUniqueNames($agent, $tools);
        $this->assertNoForbiddenKeys($agent, $tools);
        $this->assertToolsetSizes($agent, $tools);
        $this->assertPageContextWired($agent);
    }

    /**
     * Resolve each declared tool the way laravel/ai does before a turn, flattening tool-search groups. Values
     * laravel/ai would never find by name are left out.
     *
     * @param  iterable<mixed>  $tools
     * @return list<Tool>
     */
    private function resolve(iterable $tools): array
    {
        $resolved = [];

        foreach ($tools as $tool) {
            array_push($resolved, ...$this->resolveOne($tool));
        }

        return $resolved;
    }

    /**
     * One declared value as the tools laravel/ai would find by name: an agent becomes an AgentTool, a tool stays, a
     * tool-search group gives its own tools, an MCP tool or server tool is wrapped, and anything else gives none.
     *
     * @return list<Tool>
     */
    private function resolveOne(mixed $tool): array
    {
        return match (true) {
            $tool instanceof Agent => [new AgentTool($tool)],
            $tool instanceof Tool => [$tool],
            $tool instanceof ToolSearch => $this->resolve($tool->tools),
            ! is_object($tool) => [],
            McpTool::supports($tool) => [new McpTool($tool)],
            McpServerTool::supports($tool) => [new McpServerTool($tool)],
            default => [],
        };
    }

    /**
     * Fail when two tools share a name, since a tool call names only one of them.
     *
     * @param  list<Tool>  $tools
     */
    private function assertUniqueNames(Agent $agent, array $tools): void
    {
        $counts = array_count_values(array_map(ToolNameResolver::resolve(...), $tools));
        $duplicates = array_keys(array_filter($counts, fn (int $count): bool => $count > 1));

        Assert::assertEmpty($duplicates, sprintf(
            '%s has more than one tool named [%s]. Tool names must be unique within one agent.',
            $agent::class,
            implode(', ', $duplicates),
        ));
    }

    /**
     * Fail when a tool offers an input key an agent may never be offered. An MCP client tool is skipped: its schema
     * describes another server's tool.
     *
     * @param  list<Tool>  $tools
     */
    private function assertNoForbiddenKeys(Agent $agent, array $tools): void
    {
        $keys = app(ForbiddenKeys::class);
        $violations = [];

        foreach ($tools as $tool) {
            if ($tool instanceof McpTool) {
                continue;
            }

            $node = (new ObjectType($tool->schema(new JsonSchemaTypeFactory)))->toArray();
            $parameters = $tool instanceof ActionTool ? $keys->routeParameters($tool->entry()->class) : [];

            foreach ($keys->inInput($node, $parameters) as $path) {
                $violations[] = ToolNameResolver::resolve($tool).": {$path}";
            }
        }

        Assert::assertEmpty($violations, sprintf(
            '%s offers input keys an agent may never be offered: [%s]. Remove them from the tool\'s schema, or follow the "Strict agent schemas (no ids)" recipe (https://agentic-actions.com/recipes#strict-agent-schemas-no-ids).',
            $agent::class,
            implode(', ', $violations),
        ));
    }

    /**
     * Fail when one of the agent's toolsets holds more action tools than agents.max_tools_per_toolset. An agent with
     * no #[UseToolset] has no toolsets to measure.
     *
     * @param  list<Tool>  $tools
     */
    private function assertToolsetSizes(Agent $agent, array $tools): void
    {
        $limit = (int) config('agentic-actions.agents.max_tools_per_toolset');
        $oversized = [];

        foreach (Toolsets::declared($agent::class) ?? [] as $toolset) {
            $count = count(array_filter(
                $tools,
                fn (Tool $tool): bool => $tool instanceof ActionTool && in_array($toolset, $tool->entry()->toolsets, true),
            ));

            if ($count > $limit) {
                $oversized[] = "{$toolset} ({$count})";
            }
        }

        Assert::assertEmpty($oversized, sprintf(
            '%s receives more than agents.max_tools_per_toolset (%d) action tools in [%s].',
            $agent::class,
            $limit,
            implode(', ', $oversized),
        ));
    }

    /**
     * Fail when the agent's class carries #[WithPageContext] but middleware() does not return the PageContext that
     * makes the attribute take effect.
     */
    private function assertPageContextWired(Agent $agent): void
    {
        if ((new ReflectionClass($agent))->getAttributes(WithPageContext::class) === []) {
            return;
        }

        $wired = $agent instanceof HasMiddleware
            && array_filter($agent->middleware(), fn (mixed $middleware): bool => $middleware instanceof PageContext) !== [];

        Assert::assertTrue($wired, sprintf(
            '%s carries #[WithPageContext], but middleware() does not return $this->actionMiddleware(), so the page never reaches the model.',
            $agent::class,
        ));
    }
}
