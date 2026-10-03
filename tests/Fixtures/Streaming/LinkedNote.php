<?php

namespace Tests\Fixtures\Streaming;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Throwable;
use Workbench\App\Models\User;

/**
 * A Write with no labels and no touches, whose redirectTo() returns or throws what the test hook holds.
 */
#[Expose(agents: ['stream'])]
final class LinkedNote extends Action
{
    /**
     * What redirectTo() returns, or throws when it is a throwable.
     */
    public static string|Throwable|null $redirect = null;

    protected string $description = 'Save a linked note for the signed-in author.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Save a note.
     */
    public function handle(ActionContext $context): void
    {
        $context->actor(User::class)->posts()->create(['title' => 'Linked', 'body' => 'x', 'status' => 'draft']);
    }

    /**
     * The hook's URL, or its throwable.
     */
    public function redirectTo(mixed $result, ActionContext $context): ?string
    {
        if (self::$redirect instanceof Throwable) {
            throw self::$redirect;
        }

        return self::$redirect;
    }
}
