<?php

namespace Tests\Feature\Feed\Fixtures;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Workbench\App\Models\User;

/**
 * A Read that declares posts: a Read changes nothing, so the feed records nothing for it.
 */
#[Expose]
final class FeedCountPosts extends Action
{
    protected string $description = 'Count the signed-in author\'s posts.';

    protected ?Effect $effect = Effect::Read;

    protected array $touches = ['posts'];

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * The author's post count.
     */
    public function handle(ActionContext $context): int
    {
        return $context->actor(User::class)->posts()->count();
    }
}
