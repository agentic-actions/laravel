<?php

namespace Tests\Fixtures\Actions;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Effect;
use Workbench\App\Models\User;

/**
 * A Read whose handle() writes: the Read guard refuses the statement.
 */
final class ReadThatWrites extends Action
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
     * Try to write.
     */
    public function handle(ActionContext $context): mixed
    {
        return $context->actor(User::class)->posts()->create(['title' => 'Written', 'body' => 'x', 'status' => 'draft']);
    }
}
