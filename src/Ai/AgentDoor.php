<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Discovery\ActionRegistry;
use AgenticActions\Exposure\Entry;
use AgenticActions\Outcome;
use AgenticActions\Pipeline\Door;
use AgenticActions\Runner;
use AgenticActions\Surface;

/**
 * The one door every agent tool call enters. It finds the action by name, runs it as the Agent surface, keys
 * it by the tool call when the host gave no key, and hands it to the Runner, which re-reads the class, gates the
 * toolsets, prunes the arguments, translates them and never rethrows.
 *
 * @internal
 */
final class AgentDoor
{
    /**
     * Run a discovered action for an agent with these toolsets.
     *
     * @param  list<string>  $toolsets  the calling agent's toolsets
     * @param  array<string, mixed>  $arguments  the model's arguments, as the provider sent them
     */
    public function call(array $toolsets, string $name, array $arguments, ActionContext $context, ?string $callId = null): Outcome
    {
        $context = $context->withSurface(Surface::Agent);

        $candidate = app(ActionRegistry::class)->find($name);

        if ($candidate === null) {
            return Outcome::notFound(Entry::unknown($name), $context);
        }

        return app(Runner::class)->run($candidate, $arguments, $this->keyed($context, $callId), Door::Agent, $toolsets);
    }

    /**
     * The context keyed by the tool call, unless the host already set a key.
     *
     * @upstream A blank tool-call id is treated as absent.
     */
    private function keyed(ActionContext $context, ?string $callId): ActionContext
    {
        if ($context->idempotencyKey !== null || blank($callId)) {
            return $context;
        }

        return $context->withIdempotencyKey($callId);
    }
}
