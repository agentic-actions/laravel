<?php

namespace AgenticActions\Events;

use AgenticActions\Effect;
use AgenticActions\Surface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An action stopped without running handle() to the end: invalid, not found, denied or refused. Carries no input
 * values.
 *
 * @api
 */
final class ActionRefused implements ShouldDispatchAfterCommit
{
    /**
     * Create a new event instance.
     *
     * @param  string  $reason  invalid, not_found, denied or refused
     * @param  array<string, list<string>>  $failedRules
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
        public readonly string $reason,
        public readonly int $status,
        public readonly array $failedRules,
    ) {}
}
