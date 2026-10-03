<?php

namespace Workbench\App\Ai;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * The copilot of docs/copilot.md's "Confirmations", for one member of one team: the team toolset, which holds
 * DeletePost and PublishPost, and remembered conversations, so each of those calls waits for the member to confirm it.
 */
#[UseToolset('team')]
final class TeamAssistant implements Agent, Conversational, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the assistant for one member of one team.
     */
    public function __construct(public User $user, public Team $team) {}

    /**
     * What the assistant does.
     */
    public function instructions(): string
    {
        return 'You help a member of a team manage the team\'s posts. Deleting or publishing a post asks the member to confirm it on a card: say what you are about to do.';
    }

    /**
     * The member and the team the tools act for.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->team);
    }
}
