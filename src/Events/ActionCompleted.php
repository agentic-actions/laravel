<?php

namespace AgenticActions\Events;

use AgenticActions\Effect;
use AgenticActions\Surface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An action's handle() ran and returned. Carries no input values.
 *
 * @api
 */
final class ActionCompleted implements ShouldDispatchAfterCommit
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $class,
        public readonly Surface $surface,
        public readonly ?Effect $effect,
        public readonly bool $modelDriven,
        public readonly ?string $actorType,
        public readonly int|string|null $actorId,
        public readonly int|string|null $tenantId,
        public readonly string $requestId,
        public readonly float $durationMs,
    ) {}
}
