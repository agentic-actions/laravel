<?php

namespace Tests\Fixtures\Approvals;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Ai\Proposal;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Contracts\ProposesCalls;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use RuntimeException;
use Workbench\App\Models\User;

/**
 * An agent that does not store its conversations and proposes the calls that wait for a confirmation: it keeps each
 * proposal, and answers with its own sentence when one is set.
 */
#[UseToolset('approvals', 'default')]
final class ProposingAgent implements Agent, HasTools, ProposesCalls
{
    use InteractsWithActions;
    use Promptable;

    /**
     * The proposals it received.
     *
     * @var list<Proposal>
     */
    public array $proposals = [];

    /**
     * Build the agent for one author.
     */
    public function __construct(public User $user, public ?string $sentence = null, public bool $throws = false) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'Every night, tidy the signed-in author\'s posts.';
    }

    /**
     * Keep the proposal.
     */
    public function propose(Proposal $proposal): ?string
    {
        if ($this->throws) {
            throw new RuntimeException('proposal store broke');
        }

        $this->proposals[] = $proposal;

        return $this->sentence;
    }

    /**
     * The author the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user);
    }
}
