<?php

namespace AgenticActions\Ai;

use AgenticActions\Ai\Concerns\InteractsWithActions;
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
use ReflectionMethod;

/**
 * Audits one agent's tools as laravel/ai resolves them: its toolsets' action tools reached, unique names, no forbidden
 * input keys, at most agents.max_tools action tools loaded on every step, and the page context wired when the agent
 * asks for it. Every check goes through PHPUnit's Assert, so it works under PHPUnit and Pest.
 *
 * @upstream Tools are resolved the way the provider resolves them before a turn.
 *
 * @internal
 */
final class AgentToolAudit
{
    /**
     * Assert the toolsets' action tools reached, unique tool names and no forbidden keys, over the tools as laravel/ai
     * resolves them, then the action tools loaded on every step within the limit, and the page context's wiring.
     */
    public function assert(Agent $agent): void
    {
        $declared = $agent instanceof HasTools ? [...$agent->tools()] : [];
        $tools = $this->resolve($declared);

        $this->assertActionToolsReached($agent, $tools);
        $this->assertUniqueNames($agent, $tools);
        $this->assertNoForbiddenKeys($agent, $tools);
        $this->assertLoadedTools($agent, $declared);
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
     * Fail when an agent on InteractsWithActions with a #[UseToolset] or #[DeferToolset] does not implement HasTools,
     * whose tools() is the only one laravel/ai reads, or returns none of the action tools one of those attributes'
     * toolsets give this person, at its top level or inside a tool-search group: a tools() the class declares replaces
     * the trait's. Toolsets that give this person no action tools pass, and so does a tools() that keeps some of them.
     *
     * @param  list<Tool>  $tools
     */
    private function assertActionToolsReached(Agent $agent, array $tools): void
    {
        $loads = Toolsets::declared($agent::class) !== null;
        $defers = Toolsets::deferred($agent::class) !== [];

        if ((! $loads && ! $defers) || ! in_array(InteractsWithActions::class, class_uses_recursive($agent), true)) {
            return;
        }

        Assert::assertInstanceOf(HasTools::class, $agent, sprintf(
            '%s carries %s and uses InteractsWithActions, but does not implement Laravel\\Ai\\Contracts\\HasTools, so laravel/ai never asks it for its tools. Add implements HasTools to the class.',
            $agent::class,
            $loads ? '#[UseToolset]' : '#[DeferToolset]',
        ));

        $reached = self::actionToolNames($tools);

        $methods = [
            'actionTools' => [$loads, '#[UseToolset]', 'merge the package\'s tools into it: return [...$this->actionTools(), ...].'],
            'deferredActionTools' => [$defers, '#[DeferToolset]', 'put them in a tool-search group in it: return [new ToolSearch([...$this->deferredActionTools(), ...]), ...$this->actionTools()].'],
        ];

        foreach ($methods as $method => [$carries, $attribute, $fix]) {
            $offered = $carries ? self::actionToolNames((new ReflectionMethod($agent, $method))->invoke($agent)) : [];

            Assert::assertFalse($offered !== [] && array_intersect($offered, $reached) === [], sprintf(
                '%s carries %s, whose toolsets give this person %d action tools, but its tools() returns none of them: a tools() the class declares replaces the one InteractsWithActions gives it. Delete the tools() it declares, or %s',
                $agent::class,
                $attribute,
                count($offered),
                $fix,
            ));
        }
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
     * Fail when the agent loads more action tools on every step than agents.max_tools: those at the top level of its
     * tools(), for this person. The actions it finds through tool search, inside a tool-search group, do not count.
     *
     * @param  list<mixed>  $declared  its tools() as it returns them
     */
    private function assertLoadedTools(Agent $agent, array $declared): void
    {
        $limit = (int) config('agentic-actions.agents.max_tools', 20);
        $loaded = count(array_unique(self::actionToolNames($declared)));

        Assert::assertFalse($loaded > $limit, sprintf(
            '%s loads %d action tools on every step, more than agents.max_tools (%d). Move the toolsets it needs only sometimes to #[DeferToolset], or raise the limit.',
            $agent::class,
            $loaded,
            $limit,
        ));
    }

    /**
     * The names of the action tools among these values.
     *
     * @return list<string>
     */
    private static function actionToolNames(mixed $tools): array
    {
        $names = [];

        foreach (is_iterable($tools) ? $tools : [] as $tool) {
            if ($tool instanceof ActionTool) {
                $names[] = $tool->name();
            }
        }

        return $names;
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
