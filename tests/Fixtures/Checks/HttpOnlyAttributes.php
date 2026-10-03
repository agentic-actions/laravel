<?php

namespace Tests\Fixtures\Checks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * Carries Laravel 13's #[Middleware], which only an HTTP request runs, on an action agents also reach. On Laravel 12
 * the attribute class does not exist, and the attribute is never instantiated.
 */
#[Expose]
#[Middleware('throttle:5,1')]
final class HttpOnlyAttributes extends Action
{
    protected string $description = 'Send the signed-in author a digest.';

    protected ?Effect $effect = Effect::Write;

    /**
     * Any signed-in author.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor !== null;
    }

    /**
     * Never reached by these tests.
     */
    public function handle(ActionContext $context): mixed
    {
        return null;
    }
}
