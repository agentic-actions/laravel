<?php

namespace AgenticActions\Pipeline;

use AgenticActions\ActionContext;
use AgenticActions\Contracts\GovernsModelCalls;
use AgenticActions\Exposure\Entry;
use AgenticActions\ModelCallRule;
use Throwable;

/**
 * Reads the app's GovernsModelCalls for a model-driven call and says whether it waits for a person.
 *
 * @internal
 */
final class ModelCallRules
{
    /**
     * The app's rule for this model-driven call, or null (no binding, or the call is not model-driven). A throwable is
     * reported and reads as a refusal.
     */
    public static function rule(Entry $live, ActionContext $context): ?ModelCallRule
    {
        if (! app()->bound(GovernsModelCalls::class) || ! ModelDriven::detect($context)) {
            return null;
        }

        try {
            return app(GovernsModelCalls::class)->rule($live->name, $live->effect, $context);
        } catch (Throwable $exception) {
            report($exception);

            return ModelCallRule::refuse((string) trans('agentic-actions::model.not_found', [], $context->locale));
        }
    }

    /**
     * Whether a model's call waits for a person's confirmation: the app's confirm rule, or a Destructive or External
     * effect unless the app's run rule lifts it on the agent door.
     */
    public static function needsPerson(Entry $live, ?ModelCallRule $rule, bool $agentDoor): bool
    {
        if ($rule?->confirms() === true) {
            return true;
        }

        if ($live->effect?->isModelSafe() !== false) {
            return false;
        }

        return ! ($rule?->runs() === true && $agentDoor);
    }
}
