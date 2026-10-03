<?php

namespace AgenticActions\Pipeline;

use AgenticActions\ActionContext;
use AgenticActions\Queue\RunAction;
use Laravel\Ai\Gateway\ParentInvocation;

/**
 * Decides whether a model drives a call. The call stack decides, never the context a caller built.
 *
 * @internal
 */
final class ModelDriven
{
    /**
     * Whether a model drives this call: an Agent or MCP context, a queued run dispatched from one, a call made inside a
     * laravel/ai tool, a call made inside a laravel/mcp request, or a call made inside a queued run a model queued.
     */
    public static function detect(ActionContext $context): bool
    {
        if ($context->surface->isModelDriven() || $context->origin?->isModelDriven() === true) {
            return true;
        }

        if (RunAction::running()?->modelOrigin() !== null) {
            return true;
        }

        if (class_exists(ParentInvocation::class) && ParentInvocation::current()[1] !== null) {
            return true;
        }

        return app()->bound('mcp.request');
    }
}
