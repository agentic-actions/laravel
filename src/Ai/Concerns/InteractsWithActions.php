<?php

namespace AgenticActions\Ai\Concerns;

use AgenticActions\ActionContext;
use AgenticActions\Ai\ActionTool;
use AgenticActions\Ai\Toolsets;
use AgenticActions\Attributes\WithPageContext;
use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\PageContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use LogicException;
use ReflectionClass;

/**
 * Gives a laravel/ai agent the actions of its #[UseToolset] toolsets as tools, built per turn from the agent's own
 * state. The agent implements HasTools and overrides actionContext().
 *
 * @api
 */
trait InteractsWithActions
{
    /**
     * The agent's tools. A class that declares its own tools() spreads actionTools() into it instead.
     *
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return $this->actionTools();
    }

    /**
     * The actor, tenant and locale this agent's tools act for, built from the agent's own state, never from the request.
     */
    protected function actionContext(): ActionContext
    {
        throw new LogicException(static::class.' must override actionContext() and return ActionContext::agent($actor, $tenant, $locale).');
    }

    /**
     * The action tools for this agent's toolsets, built now, for this actor. Destructive and External actions come
     * only when this agent stores its conversations, so a person can confirm each call.
     *
     * @return list<ActionTool>
     */
    protected function actionTools(): array
    {
        return Actions::tools($this->actionContext(), Toolsets::of(static::class), $this instanceof Agent ? $this : null);
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
