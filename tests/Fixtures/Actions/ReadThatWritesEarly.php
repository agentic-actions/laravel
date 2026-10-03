<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Workbench\App\Models\User;

/**
 * A Read whose prepareForValidation() writes: the guard spans steps 3 to 9.
 */
final class ReadThatWritesEarly extends Action
{
    protected ?Effect $effect = Effect::Read;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Try to write before validation.
     */
    public function prepareForValidation(array $input, ActionContext $context): array
    {
        $context->actor(User::class)->posts()->create(['title' => 'Early', 'body' => 'x', 'status' => 'draft']);

        return $input;
    }

    /**
     * Never reached.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
