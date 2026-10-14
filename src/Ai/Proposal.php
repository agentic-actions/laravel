<?php

namespace AgenticActions\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Effect;

/**
 * A call a model made that waits for a person, kept instead of run by an agent that ProposesCalls. It holds what the
 * card would have shown, built after admission, validation and both authorize steps, and the model's arguments, so
 * the app can run the action later for the person who approves it (for example with Actions::attempt()), where the
 * pipeline checks everything again.
 *
 * @api
 */
final class Proposal
{
    /**
     * @param  array<string, mixed>  $arguments  the model's arguments, as the tool received them
     * @param  list<array{label: string, value: string}>  $summary  the card's rows (approvalSummary())
     */
    public function __construct(
        public readonly string $action,
        public readonly ?Effect $effect,
        public readonly array $arguments,
        public readonly string $title,
        public readonly array $summary,
        public readonly ?string $rule,
        public readonly ActionContext $context,
        public readonly ?string $toolCallId,
    ) {}
}
