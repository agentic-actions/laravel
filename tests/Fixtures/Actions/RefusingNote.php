<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;

/**
 * Always refuses, with details for web and API callers.
 */
#[Expose(web: true)]
final class RefusingNote extends Action
{
    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Refuse.
     */
    public function handle(ActionContext $context): never
    {
        throw Refusal::make('This note cannot be saved.')->details(['reason' => 'x']);
    }
}
