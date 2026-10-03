<?php

namespace Tests\Fixtures\Approvals;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\User;

/**
 * A conversational agent whose only tool is the host's HostConfirmedTool: it pauses without any action of the package.
 */
final class HostToolAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Build the agent for one author.
     */
    public function __construct(public User $user) {}

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You send notes for the signed-in author.';
    }

    /**
     * The host tool only.
     *
     * @return list<HostConfirmedTool>
     */
    public function tools(): iterable
    {
        return [new HostConfirmedTool];
    }
}
