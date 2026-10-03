<?php

namespace AgenticActions\Approvals;

use AgenticActions\Elicitation\Form;

/**
 * Which paused call an agent call answers: its conversation and tool-call id, and for a form's answer the form the
 * answer request verified. Never serialized; step 8 removes it before handle().
 *
 * @internal
 */
final class ApprovalTicket
{
    /**
     * Create a ticket. Both ids null: the catalog's ticket for an agent that can pause.
     *
     * @param  Form|null  $form  the form whose answer this call carries; step 8 takes its claim. Null on every other call.
     */
    public function __construct(
        public readonly ?string $conversationId,
        public readonly ?string $toolCallId,
        public readonly ?Form $form = null,
    ) {}

    /**
     * Whether the ticket names a real call: both ids present and not blank.
     *
     * @phpstan-assert-if-true !null $this->conversationId
     * @phpstan-assert-if-true !null $this->toolCallId
     */
    public function names(): bool
    {
        return ! blank($this->conversationId) && ! blank($this->toolCallId);
    }
}
