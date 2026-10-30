<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Rest;
use AgenticActions\Effect;

/**
 * A Read on POST, without the web open, on the path RestWrongMethods takes for its POST.
 */
#[Rest('POST', 'posts')]
final class RestReadOnPost extends Action
{
    protected string $description = 'List posts.';

    protected ?Effect $effect = Effect::Read;

    protected bool $tenantScoped = false;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Nothing.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [];
    }
}
