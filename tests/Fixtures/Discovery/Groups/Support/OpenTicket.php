<?php

namespace Tests\Fixtures\Discovery\Groups\Support;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Support\ValidatedInput;

/**
 * An optional module's action that joins the core's default toolset, so a test can see that a module widens a core
 * agent only while it is installed, and which snapshot records it.
 */
#[Expose(agents: ['default'])]
final class OpenTicket extends Action
{
    protected string $description = 'Open a support ticket.';

    protected ?Effect $effect = Effect::Read;

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
