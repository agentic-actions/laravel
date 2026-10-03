<?php

namespace Tests\Fixtures\PageContext;

use AgenticActions\Attributes\WithPageContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

/**
 * The page context on an agent with HasMiddleware but without InteractsWithActions, so nothing builds the page block.
 */
#[WithPageContext]
final class NoTraitAgent implements Agent, HasMiddleware
{
    use Promptable;

    /**
     * What the agent does.
     */
    public function instructions(): string
    {
        return 'You answer questions about the blog.';
    }

    /**
     * The agent's own step middleware.
     *
     * @return array<int, mixed>
     */
    public function middleware(): array
    {
        return [];
    }
}
