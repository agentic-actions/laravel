<?php

namespace AgenticActions\Ai\Concerns;

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\Toolsets;
use AgenticActions\Attributes\WithPageContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\PageContext;
use AgenticActions\Support\Packages;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use LogicException;
use ReflectionClass;

/**
 * Gives a laravel/ai agent the actions of its #[UseToolset] toolsets as tools, and those of its #[DeferToolset]
 * toolsets in one tool-search group, built per turn from the agent's own state. The agent implements HasTools and
 * overrides actionContext().
 *
 * @api
 */
trait InteractsWithActions
{
    /**
     * The agent's tools: the tool-search group of its #[DeferToolset] toolsets, when this person gets any of their
     * actions, then the action tools of its #[UseToolset] toolsets. A class that declares its own tools() spreads
     * actionTools() into it instead, and puts deferredActionTools() in a tool-search group of its own.
     *
     * @upstream The tool-search group comes first, so a cache mark on the last tool lands on a loaded one.
     *
     * @return iterable<int, Tool|ToolSearch>
     */
    public function tools(): iterable
    {
        $deferred = $this->deferredActionTools();

        return match (true) {
            $deferred === [] => $this->actionTools(),
            app(Packages::class)->toolSearch() => [new ToolSearch($deferred), ...$this->actionTools()],
            default => [...$deferred, ...$this->actionTools()],
        };
    }

    /**
     * The actor, tenant and locale this agent's tools act for, built from the agent's own state, never from the request.
     */
    protected function actionContext(): ActionContext
    {
        throw new LogicException(static::class.' must override actionContext() and return ActionContext::agent($actor, $tenant, $locale).');
    }

    /**
     * The action tools for this agent's #[UseToolset] toolsets, built now, for this actor. Destructive and External
     * actions come only when this agent stores its conversations, so a person can confirm each call.
     *
     * @return list<ActionTool>
     */
    protected function actionTools(): array
    {
        return Actions::tools($this->actionContext(), Toolsets::of(static::class), $this instanceof Agent ? $this : null);
    }

    /**
     * The action tools for this agent's #[DeferToolset] toolsets, built as actionTools() builds its own, leaving out
     * every action a #[UseToolset] toolset already gives it. None for an agent without #[DeferToolset].
     *
     * @return list<ActionTool>
     */
    protected function deferredActionTools(): array
    {
        if (($deferred = Toolsets::deferred(static::class)) === []) {
            return [];
        }

        $loaded = Toolsets::of(static::class);

        return array_values(array_filter(
            Actions::tools($this->actionContext(), $deferred, $this instanceof Agent ? $this : null),
            fn (ActionTool $tool): bool => array_intersect($tool->entry()->toolsets, $loaded) === [],
        ));
    }

    /**
     * The step middleware this agent's attributes ask for: the page context when the class carries #[WithPageContext].
     * An agent that implements HasMiddleware returns it from middleware(), beside its own.
     *
     * @return list<PageContext>
     */
    protected function actionMiddleware(): array
    {
        return (new ReflectionClass(static::class))->getAttributes(WithPageContext::class) === []
            ? []
            : [new PageContext($this->actionContext())];
    }
}
