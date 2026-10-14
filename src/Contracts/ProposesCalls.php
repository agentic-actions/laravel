<?php

namespace AgenticActions\Contracts;

use AgenticActions\Ai\Proposal;

/**
 * An agent that cannot pause for a person (it does not store its conversations, such as one a scheduled job or a
 * queued workflow prompts) and records the calls that would wait for a confirmation as proposals instead: a Destructive
 * or External call, or one the app's GovernsModelCalls rule confirms. Such an agent is offered those actions; each call
 * passes admission, both authorize steps and validation as a card's would, then propose() receives it, and nothing
 * runs. The app keeps the proposal for a person to review and runs it later on their say, through its own code.
 *
 * A call the app's rule runs or refuses is never proposed, and an agent that can pause gets a card instead.
 *
 * @api
 */
interface ProposesCalls
{
    /**
     * Keep this call for a person to review. Return the sentence the model reads, or null for the package's
     * (agentic-actions::model.proposed). A throwable is reported, and the model reads that the call failed.
     */
    public function propose(Proposal $proposal): ?string;
}
