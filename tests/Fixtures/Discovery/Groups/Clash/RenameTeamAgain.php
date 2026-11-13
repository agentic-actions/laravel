<?php

namespace Tests\Fixtures\Discovery\Groups\Clash;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Illuminate\Support\ValidatedInput;

/**
 * Another group's action under the core's name, so a test can see that names stay unique across groups.
 */
final class RenameTeamAgain extends Action
{
    protected string $name = 'rename-team';

    protected string $description = 'Rename the team, again.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in person.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing.
     */
    public function handle(ActionContext $context, ValidatedInput $input): null
    {
        return null;
    }
}
